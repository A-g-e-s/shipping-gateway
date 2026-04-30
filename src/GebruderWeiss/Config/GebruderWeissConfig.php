<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\GebruderWeiss\Config;

use Ages\ShippingGateway\Common\PickupAddress;

readonly class GebruderWeissConfig
{
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public int $customerId,
        public string $branchCode,
        public PickupAddress $pickupAddress,
        public string $product = 'GW_PRO_LINE',
        public string $incoterm = 'DAP',
        public string $goodsDescription = 'Goods',
        public string $apiUrl = 'https://my.api.gw-world.com/transport/transport-order/2.0.0',
        public string $oauthUrl = 'https://my.api.gw-world.com/token',
    ) {
    }
}
