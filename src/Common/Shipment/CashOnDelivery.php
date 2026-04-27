<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Shipment;

readonly class CashOnDelivery
{
    public function __construct(
        public float $amount,
        public string $variableSymbol,
        public string $currency = 'CZK',
    ) {
    }
}
