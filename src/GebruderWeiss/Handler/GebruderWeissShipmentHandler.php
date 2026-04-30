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
use Ages\ShippingGateway\GebruderWeiss\GebruderWeissApi;

class GebruderWeissShipmentHandler extends GebruderWeissApi implements ShipmentHandlerInterface
{
    public function getCarrier(): Carrier
    {
        return Carrier::GebruderWeiss;
    }

    public function createShipment(ShipmentRequest $request): array
    {
        $this->createTransportOrder($this->buildPayload($request));
        return [new ShipmentLabel(Carrier::GebruderWeiss, $request->reference, '')];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(ShipmentRequest $request): array
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
            'goodsItems' => array_values(array_map([$this, 'buildGoodsItem'], $request->parcels)),
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
    private function buildGoodsItem(Parcel $parcel): array
    {
        return [
            'quantity' => 1,
            'packageType' => $this->getPackageTypeCode($parcel->type),
            'description' => $this->config->goodsDescription,
            'stackable' => false,
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
