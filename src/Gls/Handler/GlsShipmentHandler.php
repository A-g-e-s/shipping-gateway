<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Gls\Handler;

use Ages\ShippingGateway\Common\Carrier;
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

        foreach ($request->parcels as $i => $parcel) {
            $ref = $count > 1 ? $request->reference . '-' . ($i + 1) : $request->reference;

            $entity = ParcelEntity::of(
                (string) $this->config->clientNumber,
                $ref,
                1,
                $pickup,
                $delivery,
                $services,
            );

            $data = $this->printLabels($entity);
            if (!empty($data->PrintLabelsErrorList)) {
                $errors = implode(', ', array_map(
                    fn($e) => sprintf('%s (%s)', $e->ErrorCode, $e->ErrorDescription),
                    $data->PrintLabelsErrorList,
                ));
                throw new UnexpectedValueException('GLS: ' . $errors);
            }

            $parcelNumber = $data->PrintLabelsInfoList[0]->ParcelNumber ?? $ref;
            $labelPdf = implode(array_map('chr', $data->Labels));
            $labels[] = new ShipmentLabel(Carrier::Gls, (string) $parcelNumber, $labelPdf);
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
