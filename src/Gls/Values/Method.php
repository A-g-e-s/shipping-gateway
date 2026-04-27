<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Gls\Values;

enum Method: string
{
    case PrintLabels = 'PrintLabels';
    case PrintedLabels = 'GetPrintedLabels';
    case ParcelList = 'GetParcelList';
    case ParcelStatuses = 'GetParcelStatuses';
    case PrepareLabels = 'PrepareLabels';
}
