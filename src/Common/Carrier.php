<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common;

enum Carrier: string
{
    case Gls = 'gls';
    case Ppl = 'ppl';
    case CzechPost = 'czechpost';
    case GebruderWeiss = 'gbw';
}
