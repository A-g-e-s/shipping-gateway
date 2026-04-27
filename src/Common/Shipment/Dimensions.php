<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Shipment;

readonly class Dimensions
{
    public function __construct(
        public float $length,
        public float $width,
        public float $height,
    ) {
    }
}
