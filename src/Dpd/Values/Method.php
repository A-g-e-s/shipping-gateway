<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Dpd\Values;

enum Method: string
{
    case Me = '/v1/me';
    case Shipments = '/v1/shipments';
    case Labels = '/v1/parcels/labels';
    case ShippingServices = '/v1/shipping-services';
    case PickupOrders = '/v1/pickup-orders';
    /** + /{parcelNumber}/tracking */
    case Parcels = '/v2/parcels';
}
