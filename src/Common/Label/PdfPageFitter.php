<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Label;

use Ages\ShippingGateway\Common\Data;
use Ages\ShippingGateway\Common\ShippingException;
use Mpdf\Mpdf;

/**
 * Přeskládá stránky PDF (štítky po kusech) na stránky pevné velikosti – typicky termoštítek 100 × 150 mm.
 * Každá zdrojová stránka je na vlastní stránce v měřítku 1:1, vodorovně vycentrovaná. Když se nevejde na výšku,
 * ale vejde se otočená, výsledná stránka je na šířku (150 × 100). Větší stránka se zmenší, menší se nikdy nezvětšuje.
 */
final class PdfPageFitter
{
    public const float ThermalWidth = 100.0;
    public const float ThermalHeight = 150.0;
    /** Tolerance zaokrouhlení rozměrů ze zdrojového PDF (mm). */
    private const float Tolerance = 0.5;

    private function __construct()
    {
    }

    /**
     * @param string[] $pdfs binární obsah PDF, stránky v pořadí vstupů
     * @param float $width šířka výsledné stránky v mm (na výšku)
     * @param float $height výška výsledné stránky v mm (na výšku)
     * @param bool $center svisle vycentrovat; false = štítek nahoře
     * @throws ShippingException
     */
    public static function fit(array $pdfs, float $width = self::ThermalWidth, float $height = self::ThermalHeight, bool $center = true): string
    {
        $pdfs = array_values($pdfs);
        if ($pdfs === []) {
            throw new ShippingException('PdfPageFitter: no PDF to fit');
        }

        $pdf = new Mpdf([
            'format' => [$width, $height],
            'orientation' => 'P',
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'margin_header' => 0,
            'margin_footer' => 0,
        ]);
        $temporaryFiles = [];

        try {
            foreach ($pdfs as $content) {
                // mPDF importuje jen ze souboru
                $temporaryFile = tempnam(sys_get_temp_dir(), 'label_');
                if ($temporaryFile === false || file_put_contents($temporaryFile, $content) === false) {
                    throw new ShippingException('PdfPageFitter: failed to create temporary file');
                }
                $temporaryFiles[] = $temporaryFile;

                $pageCount = $pdf->setSourceFile($temporaryFile);
                for ($page = 1; $page <= $pageCount; $page++) {
                    $template = $pdf->importPage($page);
                    $size = $pdf->getTemplateSize($template);
                    $sourceWidth = Data::float($size, 'width');
                    $sourceHeight = Data::float($size, 'height');
                    if ($sourceWidth <= 0 || $sourceHeight <= 0) {
                        throw new ShippingException('PdfPageFitter: page ' . $page . ' has no size');
                    }

                    [$landscape, $scale] = self::placement($sourceWidth, $sourceHeight, $width, $height);
                    [$pageWidth, $pageHeight] = $landscape ? [$height, $width] : [$width, $height];
                    $pdf->AddPageByArray(['orientation' => $landscape ? 'L' : 'P']);

                    $w = $sourceWidth * $scale;
                    $h = $sourceHeight * $scale;
                    $x = ($pageWidth - $w) / 2;
                    $y = $center ? ($pageHeight - $h) / 2 : 0.0;
                    $pdf->useTemplate($template, $x, $y, $w, $h);
                }
            }

            return PdfOutput::toString($pdf, 'PdfPageFitter');
        } finally {
            foreach ($temporaryFiles as $temporaryFile) {
                if (is_file($temporaryFile)) {
                    @unlink($temporaryFile);
                }
            }
        }
    }

    /**
     * @return array{bool, float} [stránka na šířku, měřítko]
     */
    private static function placement(float $sourceWidth, float $sourceHeight, float $width, float $height): array
    {
        if ($sourceWidth <= $width + self::Tolerance && $sourceHeight <= $height + self::Tolerance) {
            return [false, 1.0];
        }
        if ($sourceWidth <= $height + self::Tolerance && $sourceHeight <= $width + self::Tolerance) {
            return [true, 1.0];
        }

        // nevejde se ani otočená – zmenšit v orientaci, kde zůstane větší
        $portrait = min($width / $sourceWidth, $height / $sourceHeight);
        $landscape = min($height / $sourceWidth, $width / $sourceHeight);

        return $landscape > $portrait ? [true, $landscape] : [false, $portrait];
    }
}
