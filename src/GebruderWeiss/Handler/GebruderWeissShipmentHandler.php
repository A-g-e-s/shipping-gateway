<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\GebruderWeiss\Handler;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\ShipmentHandlerInterface;
use Ages\ShippingGateway\Common\Shipment\CashOnDelivery;
use Ages\ShippingGateway\Common\Shipment\Parcel;
use Ages\ShippingGateway\Common\Shipment\ParcelType;
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

    public function createShipment(ShipmentRequest $request): array
    {
        $ssccCodes = $this->generateSsccCodes($request);
        $this->createTransportOrder($this->buildPayload($request, $ssccCodes));
        $combinedPdf = $this->labelGenerator->generateLabels($request, $ssccCodes);

        $labels = [];
        foreach ($ssccCodes as $index => $sscc) {
            $labels[] = new ShipmentLabel(Carrier::GebruderWeiss, $sscc, $index === 0 ? $combinedPdf : '');
        }
        return $labels;
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
        $available = 17 - strlen($prefix);

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
                $this->buildShipperAddress(),
                $this->buildConsigneeAddress($request),
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
     * @return array<string, mixed>
     */
    private function buildShipperAddress(): array
    {
        return [
            'addressType' => 'SHIPPER',
            'addressReferences' => [
                ['qualifier' => 'CUSTOMER_ID', 'reference' => (string) $this->config->customerId],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildConsigneeAddress(ShipmentRequest $request): array
    {
        $r = $request->recipient;

        $address = array_filter([
            'name1' => $r->company ?? $r->fullName(),
            'name2' => $r->company !== null ? $r->fullName() : null,
            'street1' => $r->streetWithNumber(),
            'city' => $r->city,
            'zipCode' => $r->zip,
            'countryCode' => $r->country,
        ], fn($v) => $v !== null && $v !== '');

        $contact = array_filter([
            'name' => $r->firstName ?: null,
            'surname' => $r->lastName ?: null,
            'email' => $r->email ?: null,
            'phone' => $r->phone ?: null,
        ], fn($v) => $v !== null);

        $data = [
            'addressType' => 'CONSIGNEE',
            'address' => $address,
        ];

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
            $requirements['note'] = [
                'noteType' => 'GENERAL',
                'noteText' => ['text' => mb_substr($request->note, 0, 210)],
            ];
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
    private function buildGoodsItem(Parcel $parcel, string $sscc): array
    {
        $packItem = [
            'barcode' => [
                'barcodeType' => 'SSCC',
                'barcode' => $sscc,
            ],
            'measurements' => [
                [
                    'measureQualifier' => 'GROSS_WEIGHT',
                    'measureUnit' => 'KGM',
                    'measure' => (int) round($parcel->weight),
                ],
            ],
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

        return [
            'quantity' => 1,
            'packageType' => $this->getPackageTypeCode($parcel->type),
            'description' => $this->config->goodsDescription,
            'stackable' => false,
            'packItems' => [$packItem],
            'measurements' => [
                [
                    'measureQualifier' => 'GROSS_WEIGHT',
                    'measureUnit' => 'KGM',
                    'measure' => (int) round($parcel->weight),
                ],
            ],
        ];
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
            ParcelType::Package, ParcelType::PackageOversize => 'BOX',
            ParcelType::PalletEur, ParcelType::PalletCustom => 'EUP',
            ParcelType::PalletHalf => 'H1P',
            ParcelType::PalletOneWay => 'EWP',
        };
    }
}
