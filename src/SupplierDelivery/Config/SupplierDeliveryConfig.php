<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\SupplierDelivery\Config;

use Ages\ShippingGateway\Common\PickupAddress;

readonly class SupplierDeliveryConfig
{
    public function __construct(
        public PickupAddress $senderAddress,
        public ?string       $logoPath = null,
    ) {
    }
}
