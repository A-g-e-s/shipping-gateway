<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Tests;

use Ages\ShippingGateway\Common\Data;
use Mpdf\Mpdf;

/**
 * Testovací PDF s danými rozměry stránek a čtení rozměrů stránek hotového PDF (mm, zaokrouhleno).
 */
final class PdfPages
{
    /**
     * @param list<array{float, float}> $sizes [šířka, výška] v mm pro každou stránku
     */
    public static function create(array $sizes): string
    {
        $mpdf = new Mpdf(['format' => $sizes[0], 'margin_left' => 2, 'margin_right' => 2, 'margin_top' => 2, 'margin_bottom' => 2]);
        foreach ($sizes as $index => $size) {
            $mpdf->AddPageByArray(['sheet-size' => $size, 'orientation' => 'P']);
            $mpdf->WriteHTML('<p>Štítek ' . ($index + 1) . '</p>');
        }
        $pdf = $mpdf->Output('', 'S');
        if (!is_string($pdf)) {
            throw new \LogicException('mPDF returned no output');
        }
        return $pdf;
    }

    /**
     * @return list<array{float, float}>
     */
    public static function sizes(string $pdf): array
    {
        $file = tempnam(sys_get_temp_dir(), 'pdftest_');
        if ($file === false) {
            throw new \LogicException('tempnam failed');
        }
        file_put_contents($file, $pdf);
        try {
            $mpdf = new Mpdf();
            $sizes = [];
            $count = $mpdf->setSourceFile($file);
            for ($page = 1; $page <= $count; $page++) {
                $size = $mpdf->getTemplateSize($mpdf->importPage($page));
                $sizes[] = [round(Data::float($size, 'width')), round(Data::float($size, 'height'))];
            }
            return $sizes;
        } finally {
            @unlink($file);
        }
    }
}
