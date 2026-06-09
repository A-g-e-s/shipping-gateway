<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\PersonalCollection\Config;

use Ages\ShippingGateway\Common\PickupAddress;

readonly class PersonalCollectionConfig
{
    public function __construct(
        public PickupAddress $pickupAddress,
        public ?string       $logoPath = null,
    ) {
    }
}
