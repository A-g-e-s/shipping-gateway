<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Gls\Values;

enum ServiceCode: string
{
    case CashOnDelivery = 'COD';
    case ParcelShopDelivery = 'PSD';
}
