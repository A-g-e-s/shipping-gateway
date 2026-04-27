<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Shipment;

enum RecipientType
{
    case Person;
    case Company;
}
