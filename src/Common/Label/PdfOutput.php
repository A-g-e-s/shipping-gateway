<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Label;

use Ages\ShippingGateway\Common\ShippingException;
use Mpdf\Mpdf;

/**
 * Binární obsah PDF z mPDF. Output('', 'S') vrací podle typů mixed – tady se ověří, že je to text.
 */
final class PdfOutput
{
    private function __construct()
    {
    }

    /**
     * @throws ShippingException
     */
    public static function toString(Mpdf $mpdf, string $context): string
    {
        $pdf = $mpdf->Output('', 'S');
        if (!is_string($pdf)) {
            throw new ShippingException($context . ': mPDF returned no output');
        }
        return $pdf;
    }
}
