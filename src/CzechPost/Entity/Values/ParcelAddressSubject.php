<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\CzechPost\Entity\Values;

enum ParcelAddressSubject: string
{
    case Person = 'F';
    case Company = 'P';
}
