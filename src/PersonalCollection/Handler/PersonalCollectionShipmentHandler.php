<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\PersonalCollection\Handler;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\Common\ShipmentHandlerInterface;
use Ages\ShippingGateway\Common\Shipment\ShipmentLabel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\PersonalCollection\Config\PersonalCollectionConfig;
use Ages\ShippingGateway\PersonalCollection\Label\PersonalCollectionLabelGenerator;

class PersonalCollectionShipmentHandler implements ShipmentHandlerInterface
{
    private PersonalCollectionLabelGenerator $labelGenerator;

    public function __construct(PersonalCollectionConfig $config)
    {
        $this->labelGenerator = new PersonalCollectionLabelGenerator($config->pickupAddress, $config->logoPath);
    }

    public function getCarrier(): Carrier
    {
        return Carrier::PersonalCollection;
    }

    public function createShipment(ShipmentRequest $request): array
    {
        $pdf = $this->labelGenerator->generateLabels($request);

        $labels = [];
        foreach (array_keys($request->parcels) as $index) {
            $labels[] = new ShipmentLabel(Carrier::PersonalCollection, $request->reference, $index === 0 ? $pdf : '');
        }
        return $labels;
    }

    /**
     * Osobní odběr nemá tracking dopravce.
     */
    public function getParcelTracking(string $consignmentId, ?\DateTimeInterface $createdAt = null): ?ParcelTrackingInterface
    {
        return null;
    }

    public function getTrackingUrl(string $consignmentId): string
    {
        return '';
    }
}
