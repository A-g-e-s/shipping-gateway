<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Toptrans\Values;

enum LabelFormat
{
    /**
     * Termoštítek 100 × 150 mm jako u ostatních dopravců: štítky Zebra 70 × 90 mm (order/print-zebra-labels)
     * přeskládané 1:1 po jednom kusu na stránku 100 × 150 (Common\Label\PdfPageFitter).
     * Formát 100 × 150 Toptrans API přímo nenabízí.
     */
    case Thermal100x150;
    /** Arch A4 s 2 × 7 štítky – přímo z odpovědi order/send. */
    case A4;
    /** Štítek 70 × 90 mm pro tiskárnu Zebra – stahuje se z archivu dávky (order/print-zebra-labels). */
    case Zebra;
}
