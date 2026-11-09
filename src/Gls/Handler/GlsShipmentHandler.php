<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Gls\Handler;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\Data;
use Ages\ShippingGateway\Common\ShipmentHandlerInterface;
use Ages\ShippingGateway\Common\Shipment\ShipmentLabel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\Gls\Entity\AddressEntity;
use Ages\ShippingGateway\Gls\Entity\ParcelEntity;
use Ages\ShippingGateway\Gls\Entity\ServiceEntity;
use Ages\ShippingGateway\Gls\GlsApi;
use UnexpectedValueException;

class GlsShipmentHandler extends GlsApi implements ShipmentHandlerInterface
{
    public function getCarrier(): Carrier
    {
        return Carrier::Gls;
    }

    public function createShipment(ShipmentRequest $request): array
    {
        foreach ($request->parcels as $parcel) {
            if ($parcel->type->isPallet()) {
                throw new \InvalidArgumentException('GLS does not support pallet shipments');
            }
        }

        $labels = [];
        $pickup = $this->getPickupAddress();
        $delivery = $this->buildDelivery($request);
        $services = $this->buildServices($request);
        $count = count($request->parcels);

        $entity = ParcelEntity::of(
            (string) $this->config->clientNumber,
            $request->reference,
            $count,
            $pickup,
            $delivery,
            $services,
            $request->note,
        );

        $data = $this->printLabels($entity);
        $errors = Data::objects($data, 'PrintLabelsErrorList');
        if ($errors !== []) {
            throw new UnexpectedValueException('GLS: ' . self::formatErrors($errors));
        }
        $bytes = array_filter(Data::array($data, 'Labels'), 'is_int');
        if ($bytes === []) {
            throw new UnexpectedValueException('GLS: label data missing in PrintLabels response');
        }
        $infoList = Data::objects($data, 'PrintLabelsInfoList');
        if ($infoList === []) {
            throw new UnexpectedValueException('GLS: parcel info missing in PrintLabels response');
        }

        $labelPdf = implode(array_map(chr(...), $bytes));
        foreach ($infoList as $index => $info) {
            $parcelNumber = Data::string($info, 'ParcelNumber', $request->reference . '-' . ($index + 1));
            $labels[] = new ShipmentLabel(Carrier::Gls, $parcelNumber, $labelPdf);
        }

        return $labels;
    }

    private function buildDelivery(ShipmentRequest $request): AddressEntity
    {
        $r = $request->recipient;
        return AddressEntity::of(
            $r->company ?? $r->fullName(),
            $r->street,
            $r->city,
            $r->zip,
            $r->country,
            $r->houseNumber,
            null,
            $r->fullName(),
            $r->phone,
            $r->email,
        );
    }

    private function buildServices(ShipmentRequest $request): ServiceEntity
    {
        $services = ServiceEntity::of();
        if ($request->cod !== null) {
            $services->addServiceCOD(
                $request->cod->amount,
                $request->cod->variableSymbol,
                $request->cod->currency,
            );
        }
        if ($request->parcelShopCode !== null) {
            $services->addServicePSD($request->parcelShopCode);
        }
        return $services;
    }
}
