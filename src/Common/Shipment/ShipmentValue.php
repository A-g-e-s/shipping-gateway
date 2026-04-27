<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Shipment;

readonly class ShipmentValue
{
    public function __construct(
        public float $amount,
        public string $currency = 'CZK',
    ) {
    }
}
