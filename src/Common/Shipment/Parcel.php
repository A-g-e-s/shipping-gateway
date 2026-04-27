<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Shipment;

readonly class Parcel
{
    public function __construct(
        public float $weight,
        public ParcelType $type = ParcelType::Package,
        public ?Dimensions $dimensions = null,
        public ?string $reference = null,
    ) {
    }
}
