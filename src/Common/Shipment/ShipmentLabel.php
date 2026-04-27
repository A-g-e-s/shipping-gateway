<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Shipment;

use Ages\ShippingGateway\Common\Carrier;

readonly class ShipmentLabel
{
    public function __construct(
        public Carrier $carrier,
        public string $trackingNumber,
        public string $labelPdf,
    ) {
    }
}
