<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\OneByAllegro\Values;

enum Service: string
{
    /** Podle dokumentace dočasně nedostupné. */
    case Priority = 'PRIORITY';
    case Standard = 'STANDARD';
}
