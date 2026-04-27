<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\CzechPost\Entity\Values;

enum ServiceCode: string
{
    case SmsNotify = '34';
    case EmailNotify = '46';
    case PackageSizeS = 'S';
    case PackageSizeM = 'M';
    case PackageSizeL = 'L';
    case PackageSizeXl = 'XL';
    case CashOnDelivery = '41';
    case MultipartPackage = '70';
}
