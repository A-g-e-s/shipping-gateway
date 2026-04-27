<?php

namespace Ages\ShippingGateway\Ppl\Config;

use Ages\ShippingGateway\Common\PickupAddress;

class PplConfig
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly PickupAddress $pickupAddress,
        public readonly string $url = 'https://api.dhl.com/ecs/ppl/myapi2/',
        public readonly string $grantType = 'client_credentials',
        public readonly string $scope = 'myapi2',
    ) {
    }
}
