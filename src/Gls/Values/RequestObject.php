<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Gls\Values;

enum RequestObject: string
{
    case PrintLabels = 'PrintLabels';
    case PrepareLabels = 'PrepareLabels';
    case PrintedLabels = 'GetPrintedLabels';
    case ParcelStatuses = 'GetParcelStatuses';
}
