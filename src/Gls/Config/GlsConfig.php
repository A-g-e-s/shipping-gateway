<?php

namespace Ages\ShippingGateway\Gls\Config;

use Ages\ShippingGateway\Common\PickupAddress;

class GlsConfig
{
    public function __construct(
        public readonly string $username,
        public readonly string $password,
        public readonly int $clientNumber,
        public readonly PickupAddress $pickupAddress,
        public readonly string $url = 'https://api.mygls.cz/ParcelService.svc/json/',
        public readonly int $packageLimit = 99,
    ) {
    }
}
