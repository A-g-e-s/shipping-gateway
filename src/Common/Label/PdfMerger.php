<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Label;

use Ages\ShippingGateway\Common\ShippingException;
use Mpdf\Mpdf;

/**
 * Spojí několik PDF (štítky jednotlivých balíků) do jednoho přes mPDF – stránky v pořadí vstupů, velikost
 * stránky podle zdroje. Jediné PDF vrátí beze změny.
 */
final class PdfMerger
{
    private function __construct()
    {
    }

    /**
     * @param string[] $pdfs binární obsah PDF
     * @throws ShippingException
     */
    public static function merge(array $pdfs): string
    {
        $pdfs = array_values($pdfs);
        if ($pdfs === []) {
            throw new ShippingException('PdfMerger: nothing to merge');
        }
        if (count($pdfs) === 1) {
            return $pdfs[0];
        }

        $pdf = new Mpdf();
        $temporaryFiles = [];

        try {
            foreach ($pdfs as $content) {
                // mPDF importuje jen ze souboru
                $temporaryFile = tempnam(sys_get_temp_dir(), 'label_');
                if ($temporaryFile === false || file_put_contents($temporaryFile, $content) === false) {
                    throw new ShippingException('PdfMerger: failed to create temporary file');
                }
                $temporaryFiles[] = $temporaryFile;

                $pageCount = $pdf->setSourceFile($temporaryFile);
                for ($page = 1; $page <= $pageCount; $page++) {
                    $template = $pdf->importPage($page);
                    $pdf->AddPage();
                    $pdf->useTemplate($template, 0, 0, null, null, true);
                }
            }

            return PdfOutput::toString($pdf, 'PdfMerger');
        } finally {
            foreach ($temporaryFiles as $temporaryFile) {
                if (is_file($temporaryFile)) {
                    @unlink($temporaryFile);
                }
            }
        }
    }
}
