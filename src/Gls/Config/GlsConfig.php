<?php

namespace Ages\ShippingGateway\Gls\Config;

use Ages\ShippingGateway\Common\PickupAddress;

readonly class GlsConfig
{
    public function __construct(
        public string        $username,
        public string        $password,
        public int           $clientNumber,
        public PickupAddress $pickupAddress,
        public string        $url = 'https://api.mygls.cz/ParcelService.svc/json/',
        public int           $packageLimit = 99,
    ) {
    }
}
