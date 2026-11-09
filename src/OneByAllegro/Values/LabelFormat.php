<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\OneByAllegro\Values;

enum LabelFormat: string
{
    case PdfA4 = 'PDF_A4';
    case PdfA6 = 'PDF_A6';
    case Zpl = 'ZPL';
}
