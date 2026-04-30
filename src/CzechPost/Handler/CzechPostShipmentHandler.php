<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\CzechPost\Handler;

use Ages\ShippingGateway\Common\Shipment\RecipientType;
use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\ShipmentHandlerInterface;
use Ages\ShippingGateway\Common\Shipment\Parcel;
use Ages\ShippingGateway\Common\Shipment\ParcelType;
use Ages\ShippingGateway\Common\Shipment\ShipmentLabel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\CzechPost\CzechPostApi;
use Ages\ShippingGateway\CzechPost\CzechPostException;
use Ages\ShippingGateway\CzechPost\Entity\AddParcelDataEntity;
use Ages\ShippingGateway\CzechPost\Entity\AddressEntity;
use Ages\ShippingGateway\CzechPost\Entity\ConsignmentEntity;
use Ages\ShippingGateway\CzechPost\Entity\MultipartDataEntity;
use Ages\ShippingGateway\CzechPost\Entity\ParcelAddressEntity;
use Ages\ShippingGateway\CzechPost\Entity\ParcelParamsEntity;
use Ages\ShippingGateway\CzechPost\Entity\ParcelServicesEntity;
use Ages\ShippingGateway\CzechPost\Entity\Values\ParcelAddressSubject;
use Ages\ShippingGateway\CzechPost\Entity\Values\PrefixParcelCode;
use Ages\ShippingGateway\CzechPost\Entity\Values\ServiceCode;

class CzechPostShipmentHandler extends CzechPostApi implements ShipmentHandlerInterface
{
    public function getCarrier(): Carrier
    {
        return Carrier::CzechPost;
    }

    public function createShipment(ShipmentRequest $request): array
    {
        $parcels = array_values($request->parcels);
        $total = count($parcels);

        $header = $this->prepareParcelServiceHeader();
        $address = $this->buildAddress($request);
        $services = $this->buildServices($request);

        $firstRef = $total > 1 ? $request->reference . '-1' : $request->reference;
        $firstParams = $this->buildParams($parcels[0], $firstRef, $request, $total > 1 ? [1, $total] : null);

        if ($parcels[0]->type === ParcelType::PackageOversize) {
            $services->addService(ServiceCode::BulkyParcel);
        }

        if ($total > 1) {
            $services->addService(ServiceCode::MultipartPackage);
        }

        $consignment = ConsignmentEntity::of($firstParams, $address, $services);

        $multipartData = [];
        for ($i = 1; $i < $total; $i++) {
            $ref = $request->reference . '-' . ($i + 1);
            $multipartServices = [ServiceCode::MultipartPackage];
            if ($parcels[$i]->type === ParcelType::PackageOversize) {
                $multipartServices[] = ServiceCode::BulkyParcel;
            }
            $multipartData[] = MultipartDataEntity::of(
                AddParcelDataEntity::of(
                    $ref,
                    $this->prefixCode($parcels[$i]),
                    $parcels[$i]->weight,
                    $i + 1,
                    $total,
                ),
                ParcelServicesEntity::of(...$multipartServices),
            )->toArray();
        }

        $response = $this->parcelService($header, $consignment->toArray(), $multipartData ?: null);

        return $this->parseResponse($response);
    }

    private function buildParams(Parcel $parcel, string $recordId, ShipmentRequest $request, ?array $multipart): ParcelParamsEntity
    {
        $params = ParcelParamsEntity::of(
            $recordId,
            $this->prefixCode($parcel),
            $parcel->weight,
            $request->value->amount,
            $request->note,
        );

        if ($request->cod !== null) {
            $params->addCashOnDelivery($request->cod->amount, $request->cod->currency, $request->cod->variableSymbol);
        }

        if ($multipart !== null) {
            $params->addMultipartInfo($multipart[0], $multipart[1]);
        }

        return $params;
    }

    private function buildAddress(ShipmentRequest $request): ParcelAddressEntity
    {
        $r = $request->recipient;
        $subject = $r->type === RecipientType::Company
            ? ParcelAddressSubject::Company
            : ParcelAddressSubject::Person;

        $address = AddressEntity::of(
            $r->street,
            $r->city,
            $r->zip,
            $r->country,
            $r->houseNumber,
        );

        return ParcelAddressEntity::of(
            $subject,
            $address,
            $r->firstName,
            $r->lastName,
            $r->company,
            $r->phone,
            $r->email,
        );
    }

    private function buildServices(ShipmentRequest $request): ParcelServicesEntity
    {
        $services = ParcelServicesEntity::of();
        if ($request->recipient->email !== '') {
            $services->addService(ServiceCode::EmailNotify);
        }
        if ($request->recipient->phone !== '') {
            $services->addService(ServiceCode::SmsNotify);
        }
        if ($request->cod !== null) {
            $services->addService(ServiceCode::CashOnDelivery);
        }
        return $services;
    }

    private function prefixCode(Parcel $parcel): PrefixParcelCode
    {
        return match ($parcel->type) {
            ParcelType::Package         => PrefixParcelCode::Package,
            ParcelType::PackageOversize => PrefixParcelCode::PackageOversize,
            default                     => throw new \InvalidArgumentException('Czech Post does not support pallet shipments'),
        };
    }

    /**
     * @param mixed $response
     * @return ShipmentLabel[]
     * @throws CzechPostException
     */
    private function parseResponse(mixed $response): array
    {
        if (!is_array($response)) {
            throw new CzechPostException('Czech Post: unexpected response type');
        }

        $header = $response['responseHeader'] ?? null;
        if (!is_array($header)) {
            throw new CzechPostException('Czech Post: missing responseHeader');
        }

        $responseCode = $header['resultHeader']['responseCode'] ?? null;
        if ($responseCode !== 1) {
            $msg = sprintf('responseCode %s', $responseCode ?? 'null');
            throw new CzechPostException('Czech Post: ' . $msg, (int) $responseCode);
        }

        $labelPdf = base64_decode((string) ($header['responsePrintParams']['file'] ?? ''));
        $parcelData = $header['resultParcelData'] ?? [];
        if (!is_array($parcelData) || count($parcelData) === 0) {
            throw new CzechPostException('Czech Post: no parcel data in response');
        }

        $labels = [];
        foreach ($parcelData as $package) {
            $trackingNumber = (string) ($package['parcelCode'] ?? $package['recordNumber'] ?? '');
            $labels[] = new ShipmentLabel(Carrier::CzechPost, $trackingNumber, $labelPdf);
        }

        return $labels;
    }
}
