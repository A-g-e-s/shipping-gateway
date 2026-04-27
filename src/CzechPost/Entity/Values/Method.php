<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\CzechPost\Entity\Values;

enum Method: string
{
    case SendConsignment = 'parcelService';
    case Tracking = 'parcelStatus';
}
