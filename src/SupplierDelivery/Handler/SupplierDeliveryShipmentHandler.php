<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\SupplierDelivery\Handler;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\Common\ShipmentHandlerInterface;
use Ages\ShippingGateway\Common\Shipment\ShipmentLabel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\PersonalCollection\Label\PersonalCollectionLabelGenerator;
use Ages\ShippingGateway\SupplierDelivery\Config\SupplierDeliveryConfig;

/**
 * Doručení dodavatelem – zboží rozváží odesílatel vlastním autem. Žádné API dopravce,
 * jen vlastní štítek (stejný jako u osobního odběru, s adresou odesílatele).
 */
class SupplierDeliveryShipmentHandler implements ShipmentHandlerInterface
{
    private PersonalCollectionLabelGenerator $labelGenerator;

    public function __construct(SupplierDeliveryConfig $config)
    {
        $this->labelGenerator = new PersonalCollectionLabelGenerator(
            $config->senderAddress,
            $config->logoPath,
            'DORUČENÍ DODAVATELEM',
            'ODESÍLATEL:',
        );
    }

    public function getCarrier(): Carrier
    {
        return Carrier::SupplierDelivery;
    }

    public function createShipment(ShipmentRequest $request): array
    {
        $pdf = $this->labelGenerator->generateLabels($request);

        $labels = [];
        foreach (array_keys($request->parcels) as $index) {
            $labels[] = new ShipmentLabel(Carrier::SupplierDelivery, $request->reference, $index === 0 ? $pdf : '');
        }
        return $labels;
    }

    /**
     * Doručení dodavatelem nemá tracking dopravce.
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
