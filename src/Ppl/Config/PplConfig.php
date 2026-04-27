<?php

namespace Ages\ShippingGateway\Ppl\Config;

use Ages\ShippingGateway\Common\PickupAddress;

readonly class PplConfig
{
    public function __construct(
        public string        $clientId,
        public string        $clientSecret,
        public PickupAddress $pickupAddress,
        public string        $url = 'https://api.dhl.com/ecs/ppl/myapi2/',
        public string        $grantType = 'client_credentials',
        public string        $scope = 'myapi2',
    ) {
    }
}
