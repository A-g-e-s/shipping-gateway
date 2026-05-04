<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Ppl\Handler;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\ShipmentHandlerInterface;
use Ages\ShippingGateway\Common\Shipment\ShipmentLabel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\Ppl\Entity\AddressEntity;
use Ages\ShippingGateway\Ppl\Entity\CashOnDeliveryEntity;
use Ages\ShippingGateway\Ppl\Entity\ParcelEntity;
use Ages\ShippingGateway\Ppl\Entity\SpecificDeliveryEntity;
use Ages\ShippingGateway\Ppl\PplApi;

class PplShipmentHandler extends PplApi implements ShipmentHandlerInterface
{
    public function getCarrier(): Carrier
    {
        return Carrier::Ppl;
    }

    public function createShipment(ShipmentRequest $request): array
    {
        foreach ($request->parcels as $parcel) {
            if ($parcel->type->isPallet()) {
                throw new \InvalidArgumentException('PPL does not support pallet shipments');
            }
        }

        $labels = [];
        $pickup = $this->getPickupAddress();
        $delivery = $this->buildDelivery($request);
        $cod = $this->buildCod($request);
        $specific = SpecificDeliveryEntity::of($request->parcelShopCode);
        $count = count($request->parcels);
        $entity = ParcelEntity::of($request->reference, $count, $pickup, $delivery, $specific, $cod);

        $batchId = $this->createBatch($entity);
        $status = $this->waitForBatch($batchId);
        $labelPdf = $this->extractLabelPdf($status);

        foreach ($this->extractTrackingNumbers($status, $request->reference) as $trackingNumber) {
            $labels[] = new ShipmentLabel(Carrier::Ppl, $trackingNumber, $labelPdf);
        }

        return $labels;
    }

    private function waitForBatch(string $batchId): \stdClass
    {
        for ($i = 0; $i < 6; $i++) {
            $status = $this->getStatus($batchId);
            if ($status !== null) {
                return $status;
            }
            usleep(500_000);
        }
        throw new ShippingException('PPL: batch processing timeout for batch ' . $batchId);
    }

    /**
     * @return string[]
     */
    private function extractTrackingNumbers(\stdClass $status, string $fallbackRef): array
    {
        if (!isset($status->items) || !is_array($status->items) || $status->items === []) {
            throw new ShippingException('PPL: batch response does not contain any shipments');
        }

        $trackingNumbers = [];
        foreach ($status->items as $item) {
            if (!$item instanceof \stdClass) {
                continue;
            }
            $trackingNumbers[] = (string) ($item->shipmentNumber ?? $fallbackRef);
        }

        if ($trackingNumbers === []) {
            throw new ShippingException('PPL: shipment numbers not found in batch response');
        }

        return $trackingNumbers;
    }

    private function extractLabelPdf(\stdClass $status): string
    {
        $labelUrl = $status->completeLabel?->labelUrls[0]
            ?? $status->items[0]?->labelUrl
            ?? null;
        if ($labelUrl === null) {
            throw new ShippingException('PPL: label URL not found in batch response (completeLabel.labelUrls[0] / items[0].labelUrl)');
        }

        return $this->getLabel((string) $labelUrl);
    }

    private function buildDelivery(ShipmentRequest $request): AddressEntity
    {
        $r = $request->recipient;
        return AddressEntity::of(
            $r->fullName(),
            $r->streetWithNumber(),
            $r->city,
            $r->zip,
            $r->country,
            null,
            $r->company,
            $r->phone,
            $r->email,
        );
    }

    private function buildCod(ShipmentRequest $request): ?CashOnDeliveryEntity
    {
        if ($request->cod === null) {
            return null;
        }
        return CashOnDeliveryEntity::of(
            $request->cod->amount,
            $request->cod->variableSymbol,
            $request->cod->currency,
        );
    }
}
