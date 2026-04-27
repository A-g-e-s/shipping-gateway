<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\CzechPost\Entity\Values;

enum PrefixParcelCode: string
{
    case Package = 'DR';
    case PackageOversize = 'BN';
    case Psd = 'NB';
}
