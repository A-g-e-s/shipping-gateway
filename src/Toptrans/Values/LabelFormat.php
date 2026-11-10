<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Toptrans\Values;

enum LabelFormat
{
    /** Arch A4 s 2 × 7 štítky – přímo z odpovědi order/send. */
    case A4;
    /** Štítek 70 × 90 mm pro tiskárnu Zebra – stahuje se z archivu dávky (order/print-zebra-labels). */
    case Zebra;
}
