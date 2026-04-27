<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Shipment;

readonly class ShipmentLabel
{
    public function __construct(
        public string $carrier,
        public string $trackingNumber,
        public string $labelPdf,
    ) {
    }
}
