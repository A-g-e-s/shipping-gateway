<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Ppl\Values;

enum Method: string
{
    case AccessToken = 'login/getAccessToken';
    case ShipmentBatch = 'shipment/batch';
    case Shipment = 'shipment';
    case ShipmentBatchLabel = 'label';
    case Product = 'codelist/product';
}
