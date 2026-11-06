<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\GebruderWeiss\Handler;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\ShipmentAlreadyExistsException;
use Ages\ShippingGateway\Common\ShipmentHandlerInterface;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\Common\Shipment\CashOnDelivery;
use Ages\ShippingGateway\Common\Shipment\ImportRequest;
use Ages\ShippingGateway\Common\Shipment\Parcel;
use Ages\ShippingGateway\Common\Shipment\ParcelType;
use Ages\ShippingGateway\Common\Shipment\RecipientAddress;
use Ages\ShippingGateway\Common\Shipment\ShipmentLabel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\GebruderWeiss\Config\GebruderWeissConfig;
use Ages\ShippingGateway\GebruderWeiss\GebruderWeissApi;
use Ages\ShippingGateway\GebruderWeiss\Label\GebruderWeissLabelGenerator;

class GebruderWeissShipmentHandler extends GebruderWeissApi implements ShipmentHandlerInterface
{
    private GebruderWeissLabelGenerator $labelGenerator;

    public function __construct(GebruderWeissConfig $config)
    {
        parent::__construct($config);
        $this->labelGenerator = new GebruderWeissLabelGenerator($config);
    }

    public function getCarrier(): Carrier
    {
        return Carrier::GebruderWeiss;
    }

    /**
     * Štítky se vyrobí před objednáním přepravy: když se generování nepovede, k dopravci nic neodejde.
     * Všechny štítky jsou v jednom PDF u prvního balíku, ostatní mají prázdný obsah.
     *
     * @throws ShipmentAlreadyExistsException objednávka se stejnou referencí už existuje; nese štítky
     *         se stejnými SSCC (odvozené z reference a pořadí balíku), platné, jen když jsou balíky stejné
     *         jako při prvním odeslání
     */
    public function createShipment(ShipmentRequest $request): array
    {
        $ssccCodes = $this->generateSsccCodes($request);
        $combinedPdf = $this->labelGenerator->generateLabels($request, $ssccCodes);

        $labels = [];
        foreach (array_keys($ssccCodes) as $index) {
            $labels[] = new ShipmentLabel(Carrier::GebruderWeiss, $request->reference, $index === 0 ? $combinedPdf : '');
        }

        try {
            $this->createTransportOrder($this->buildPayload($request, $ssccCodes));
        } catch (ShipmentAlreadyExistsException $e) {
            throw new ShipmentAlreadyExistsException($e->getMessage(), $labels, $e);
        }

        return $labels;
    }

    /**
     * Objedná svoz od dodavatele na naši adresu. Štítky lepí dopravce při vyzvednutí, SSCC se proto neposílá.
     *
     * @throws ShippingException
     */
    public function createImport(ImportRequest $request): void
    {
        if ($request->parcels === []) {
            throw new ShippingException('GBW import: no parcels');
        }
        if ($request->collectionDate < new \DateTimeImmutable('today')) {
            throw new ShippingException('GBW import: collection date cannot be in the past');
        }

        $this->createTransportOrder($this->buildImportPayload($request));
    }

    /**
     * @return string[]
     */
    private function generateSsccCodes(ShipmentRequest $request): array
    {
        $codes = [];
        foreach (array_keys($request->parcels) as $index) {
            $codes[$index] = $this->generateSscc($request->reference, $index);
        }
        return $codes;
    }

    private function generateSscc(string $reference, int $parcelIndex): string
    {
        $prefix = substr($this->config->ssccPrefix, 0, 16);
        $hash = sprintf('%013d', abs(crc32($reference . '|' . $parcelIndex)) % 10_000_000_000_000);
        $data = str_pad(substr($prefix . $hash, 0, 17), 17, '0');
        return $data . $this->gs1CheckDigit($data);
    }

    private function gs1CheckDigit(string $digits): string
    {
        $sum = 0;
        $len = strlen($digits);
        for ($i = 0; $i < $len; $i++) {
            $digit = (int) $digits[$len - 1 - $i];
            $sum += ($i % 2 === 0) ? $digit * 3 : $digit;
        }
        return (string) ((10 - ($sum % 10)) % 10);
    }

    /**
     * @param string[] $ssccCodes
     * @return array<string, mixed>
     */
    private function buildPayload(ShipmentRequest $request, array $ssccCodes): array
    {
        $order = [
            'customerId' => $this->config->customerId,
            'customerReference' => $request->reference,
            'branchCode' => $this->config->branchCode,
            'transportAddress' => [
                $this->buildPickupAddress('SHIPPER'),
                $this->buildRecipientAddress('CONSIGNEE', $request->recipient),
                $this->buildFreightPayerAddress(),
            ],
            'transportRequirements' => $this->buildTransportRequirements($request),
            'goodsItems' => array_map(
                fn(Parcel $parcel, int $index) => $this->buildGoodsItem($parcel, $ssccCodes[$index]),
                $request->parcels,
                array_keys($request->parcels),
            ),
        ];

        if ($request->cod !== null) {
            $order['values'] = $this->buildCodValues($request->cod);
        }

        return ['transportOrder' => $order];
    }

    /**
     * Odesílatel je dodavatel, příjemce naše pickupAddress, dopravu platíme my.
     *
     * @return array<string, mixed>
     */
    private function buildImportPayload(ImportRequest $request): array
    {
        $requirements = [
            'incoterm' => array_filter([
                'code' => $this->config->importIncoterm,
                'location' => mb_substr($request->shipper->city, 0, 35) ?: null,
            ], fn($v) => $v !== null),
            'transportProduct' => ['product' => $this->config->product],
            'collectionDate' => $request->collectionDate->format('Y-m-d'),
        ];

        $note = $this->buildImportNote($request);
        if ($note !== null) {
            $requirements['note'] = $this->buildNote($note);
        }

        return [
            'transportOrder' => [
                'customerId' => $this->config->customerId,
                'customerReference' => $request->reference,
                'branchCode' => $this->config->branchCode,
                'transportAddress' => [
                    $this->buildRecipientAddress('SHIPPER', $request->shipper),
                    $this->buildPickupAddress('CONSIGNEE'),
                    $this->buildFreightPayerAddress(),
                ],
                'transportRequirements' => $requirements,
                'goodsItems' => array_map(
                    fn(Parcel $parcel) => $this->buildGoodsItem($parcel, null),
                    array_values($request->parcels),
                ),
            ],
        ];
    }

    /**
     * Dodavatel jako odesílatel nesmí mít kontakt (GBW ho bere jen u příjemce),
     * telefon pro řidiče proto jde na začátek poznámky, aby ho neuřízl limit délky.
     */
    private function buildImportNote(ImportRequest $request): ?string
    {
        $phone = trim($request->shipper->phone);
        $parts = array_filter([
            $phone !== '' ? 'Tel. ' . $phone : null,
            $request->note,
        ], fn($v) => $v !== null && $v !== '');

        return $parts !== [] ? implode(' – ', $parts) : null;
    }

    /**
     * Naše adresa z konfigurace – odesílatel u exportu, příjemce u importu.
     *
     * @return array<string, mixed>
     */
    private function buildPickupAddress(string $addressType): array
    {
        $p = $this->config->pickupAddress;

        $data = [
            'addressType' => $addressType,
            'address' => [
                'name1' => $p->name,
                'street1' => $p->getStreetWithNumber(),
                'city' => $p->city,
                'zipCode' => $p->zip,
                'countryCode' => $p->country,
            ],
        ];

        if ($addressType === 'CONSIGNEE') {
            $contact = array_filter([
                'email' => $p->email ?: null,
                'phone' => $p->phone ?: null,
            ], fn($v) => $v !== null);

            if ($contact !== []) {
                $data['contacts'] = [$contact];
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFreightPayerAddress(): array
    {
        return [
            'addressType' => 'FREIGHT_PAYER',
            'addressReferences' => [
                [
                    'qualifier' => 'CUSTOMER_ID',
                    'reference' => (string) $this->config->customerId,
                ],
            ],
        ];
    }

    /**
     * Zadaná adresa – příjemce u exportu, dodavatel (odesílatel) u importu.
     *
     * @return array<string, mixed>
     */
    private function buildRecipientAddress(string $addressType, RecipientAddress $r): array
    {
        $address = array_filter([
            'name1' => $r->company ?? $r->fullName(),
            'name2' => $r->company !== null ? $r->fullName() : null,
            'street1' => $r->streetWithNumber(),
            'city' => $r->city,
            'zipCode' => $r->zip,
            'countryCode' => $r->country,
        ], fn($v) => $v !== null && $v !== '');

        $data = [
            'addressType' => $addressType,
            'address' => $address,
        ];

        // GBW přijímá kontakt jen u příjemce, u jiné adresy vrací 400 „invalid parameter value: contact“.
        if ($addressType !== 'CONSIGNEE') {
            return $data;
        }

        $contact = array_filter([
            'name' => $r->firstName ?: null,
            'surname' => $r->lastName ?: null,
            'email' => $r->email ?: null,
            'phone' => $r->phone ?: null,
        ], fn($v) => $v !== null);

        if (!empty($contact)) {
            $data['contacts'] = [$contact];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTransportRequirements(ShipmentRequest $request): array
    {
        $requirements = [
            'incoterm' => ['code' => $this->config->incoterm],
            'transportProduct' => ['product' => $this->config->product],
        ];

        if ($request->note !== null) {
            $requirements['note'] = $this->buildNote($request->note);
        }

        if ($request->cod !== null) {
            $textKey = ['qualifier' => 'COD_CASH'];
            if (strlen($request->cod->variableSymbol) >= 2) {
                $textKey['reference'] = $request->cod->variableSymbol;
            }
            $requirements['textKeys'] = [$textKey];
        }

        return $requirements;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildNote(string $note): array
    {
        return [
            'noteType' => 'GENERAL',
            'noteText' => ['text' => mb_substr($note, 0, 100)],
        ];
    }

    /**
     * Bez SSCC (import – štítky lepí dopravce) se packItems vynechá: GBW v packItem vyžaduje barcode
     * (jinak 400 „missing mandatory parameter: [packItem.barcode]“), takže zůstane jen počet, typ a váha.
     *
     * @return array<string, mixed>
     */
    private function buildGoodsItem(Parcel $parcel, ?string $sscc): array
    {
        $weight = [
            [
                'measureQualifier' => 'GROSS_WEIGHT',
                'measureUnit' => 'KGM',
                'measure' => (int) round($parcel->weight),
            ],
        ];

        $goodsItem = [
            'quantity' => 1,
            'packageType' => $this->getPackageTypeCode($parcel->type),
            'description' => $this->config->goodsDescription,
            'stackable' => false,
        ];

        if ($sscc !== null) {
            $packItem = [
                'barcode' => [
                    'barcodeType' => 'SSCC',
                    'barcode' => '00' . $sscc,
                ],
                'measurements' => $weight,
            ];

            if ($parcel->dimensions !== null) {
                $d = $parcel->dimensions;
                $packItem['dimension'] = [
                    'length' => round($d->length / 100, 4),
                    'width' => round($d->width / 100, 4),
                    'height' => round($d->height / 100, 4),
                    'dimensionUnit' => 'MTR',
                ];
            }

            $goodsItem['packItems'] = [$packItem];
        }

        $goodsItem['measurements'] = $weight;

        return $goodsItem;
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function buildCodValues(CashOnDelivery $cod): array
    {
        return [
            [
                'qualifier' => 'CASH_ON_DELIVERY',
                'amount' => $cod->amount,
                'currency' => $cod->currency,
            ],
        ];
    }

    private function getPackageTypeCode(ParcelType $type): string
    {
        return match ($type) {
            ParcelType::Package, ParcelType::PackageOversize => 'BAL',
            ParcelType::PalletEur, ParcelType::PalletCustom => 'EUP',
            ParcelType::PalletHalf => 'HPA',
            ParcelType::PalletOneWay => 'EWP',
        };
    }
}
