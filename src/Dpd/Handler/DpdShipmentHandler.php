<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Dpd\Handler;

use Ages\ShippingGateway\Common\AddressFormatter;
use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\Shipment\Parcel;
use Ages\ShippingGateway\Common\Shipment\RecipientType;
use Ages\ShippingGateway\Common\Shipment\ShipmentLabel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\Common\ShipmentHandlerInterface;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\Dpd\DpdApi;

class DpdShipmentHandler extends DpdApi implements ShipmentHandlerInterface
{
    private const int TextLimit = 35;
    private const int VariableSymbolLimit = 10;
    private const string CodPayment = 'CashOrCard';
    private const int GramsPerKilogram = 1000;
    private const int CentsPerUnit = 100;

    public function getCarrier(): Carrier
    {
        return Carrier::Dpd;
    }

    /**
     * Založí zásilku a stáhne štítky. Každý ShipmentLabel nese číslo svého balíku (14 číslic),
     * labelPdf je u všech stejné (kombinovaný tisk všech balíků).
     */
    public function createShipment(ShipmentRequest $request): array
    {
        if ($request->parcels === []) {
            throw new \InvalidArgumentException('DPD: shipment has no parcels');
        }
        foreach ($request->parcels as $parcel) {
            if (!in_array($parcel->type, Carrier::Dpd->supportedParcelTypes(), true)) {
                throw new \InvalidArgumentException('DPD does not support parcel type ' . $parcel->type->name);
            }
        }

        $created = $this->createShipments([$this->buildShipment($request)]);
        $parcelNumbers = $this->extractParcelNumbers($created);
        if (count($parcelNumbers) !== count($request->parcels)) {
            throw new ShippingException(sprintf(
                'DPD: expected %d parcel numbers, got %d (%s)',
                count($request->parcels),
                count($parcelNumbers),
                implode(', ', $parcelNumbers),
            ));
        }

        try {
            $labelPdf = $this->getLabels($parcelNumbers);
        } catch (ShippingException $e) {
            throw new ShippingException(
                'DPD: parcels ' . implode(', ', $parcelNumbers) . ' were created, but label download failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        return array_map(
            static fn (string $number): ShipmentLabel => new ShipmentLabel(Carrier::Dpd, $number, $labelPdf),
            $parcelNumbers,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildShipment(ShipmentRequest $request): array
    {
        $shipment = [
            'customer' => ['dsw' => $this->config->customerDsw],
            'shipmentType' => 'Standard',
            'sender' => $this->buildSender(),
            'receiver' => $this->buildReceiver($request),
            'references' => ['ref1' => mb_substr($request->reference, 0, self::TextLimit)],
            'parcels' => $this->buildParcels($request),
        ];

        $services = [];
        if ($this->config->notification) {
            $services['notification'] = true;
        }
        if ($request->parcelShopCode !== null && trim($request->parcelShopCode) !== '') {
            $services['pickupPoint'] = trim($request->parcelShopCode);
        }
        if ($request->cod !== null && $request->cod->amount > 0) {
            $cod = [
                'amountCents' => self::cents($request->cod->amount),
                'currency' => strtoupper($request->cod->currency),
                'payment' => self::CodPayment,
            ];
            $vs = substr((string) preg_replace('~\D+~', '', $request->cod->variableSymbol), 0, self::VariableSymbolLimit);
            if ($vs !== '') {
                $cod['variableSymbol'] = $vs;
            }
            $services['cashOnDelivery'] = $cod;
        }
        if ($services !== []) {
            $shipment['services'] = $services;
        }

        return $shipment;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSender(): array
    {
        if ($this->config->senderAddressId !== null) {
            return ['it4emId' => $this->config->senderAddressId];
        }

        $p = $this->config->pickupAddress;
        return [
            'info' => [
                'name1' => mb_substr($p->name, 0, self::TextLimit),
                'contact' => array_filter([
                    'phone' => AddressFormatter::phone($p->phone, $p->country),
                    'email' => $p->email,
                ]),
            ],
            'address' => self::address($p->street, $p->houseNumber, $p->zip, $p->city, $p->country),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReceiver(ShipmentRequest $request): array
    {
        $r = $request->recipient;
        $person = $r->fullName();
        $company = $r->company !== null ? trim($r->company) : '';
        if ($company === '' && $r->type === RecipientType::Company) {
            $company = $person;
        }

        $info = ['name1' => mb_substr($company !== '' ? $company : $person, 0, self::TextLimit)];
        if ($company !== '' && $person !== '' && $person !== $company) {
            $info['name2'] = mb_substr($person, 0, self::TextLimit);
        }
        $contact = array_filter([
            'person' => mb_strlen($person) >= 2 ? $person : null,
            'phone' => AddressFormatter::phone($r->phone, $r->country),
            'email' => $r->email,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
        if ($contact !== []) {
            $info['contact'] = $contact;
        }

        return [
            'info' => $info,
            'address' => self::address($r->street, $r->houseNumber, $r->zip, $r->city, $r->country),
        ];
    }

    /**
     * @return array<mixed>
     */
    private function buildParcels(ShipmentRequest $request): array
    {
        $count = count($request->parcels);
        $insurancePerParcel = $this->config->insureValue && $request->value->amount > 0
            ? self::cents($request->value->amount / $count)
            : 0;

        return array_map(function (Parcel $parcel) use ($request, $insurancePerParcel): array {
            $item = [
                'weightGrams' => max(1, (int) round($parcel->weight * self::GramsPerKilogram)),
                'references' => array_filter([
                    'ref1' => mb_substr($request->reference, 0, self::TextLimit),
                    'ref2' => $parcel->reference !== null ? mb_substr($parcel->reference, 0, self::TextLimit) : null,
                ]),
            ];
            if ($insurancePerParcel > 0) {
                $item['additionalServices'] = ['insurance' => [
                    'amountCents' => $insurancePerParcel,
                    'currency' => strtoupper($request->value->currency),
                ]];
            }
            return $item;
        }, $request->parcels);
    }

    /**
     * @return array<string, mixed>
     */
    private static function address(string $street, ?string $houseNumber, string $zip, string $city, string $country): array
    {
        [$streetName, $number] = AddressFormatter::splitStreet($street, $houseNumber);
        return array_filter([
            'street' => mb_substr($streetName, 0, self::TextLimit),
            'houseNumber' => $number,
            'postalCode' => $zip,
            'city' => mb_substr($city, 0, self::TextLimit),
            'country' => ['isoAlpha2' => strtoupper($country)],
        ], static fn (string|array $value): bool => $value !== '');
    }

    /**
     * @param array<mixed> $shipments odpověď POST /shipments
     * @return string[]
     */
    private function extractParcelNumbers(array $shipments): array
    {
        $shipment = $shipments[0] ?? null;
        $parcels = is_array($shipment) ? ($shipment['parcels'] ?? null) : null;
        if (!is_array($parcels)) {
            throw new ShippingException('DPD: shipment response does not contain parcels');
        }

        $numbers = [];
        foreach ($parcels as $parcel) {
            $number = is_array($parcel) && is_array($parcel['parcelNumbers'] ?? null) ? ($parcel['parcelNumbers']['main'] ?? null) : null;
            if (is_string($number) || is_int($number)) {
                $numbers[] = (string) $number;
            }
        }
        return $numbers;
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * self::CentsPerUnit);
    }
}
