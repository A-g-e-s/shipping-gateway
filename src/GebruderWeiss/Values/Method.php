<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\GebruderWeiss\Values;

enum Method: string
{
    case TransportOrder      = 'transport-order';
    case OrderCurrentStatus  = 'orders/%s/current-status';
    case OrderStatus         = 'orders/%s/status';
    case PackageStatus       = 'packages/%s/status';

    public function path(string ...$params): string
    {
        return $params !== [] ? sprintf($this->value, ...$params) : $this->value;
    }
}
