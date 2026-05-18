<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\GebruderWeiss\Values;

enum TokenScope: string
{
    case TransportOrder = 'API_CUSAPI_TRANSPORT_ORDER_CREATE';
    case OrdersStatus   = 'API_CUSTNT_ORDERS_STATUS_READ';
    case PackagesStatus = 'API_CUSTNT_PACKAGES_STATUS_READ';
}
