<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Tests\Common\Label;

use Ages\ShippingGateway\Common\Label\PdfMerger;
use Ages\ShippingGateway\Common\ShippingException;
use Mpdf\Mpdf;
use PHPUnit\Framework\TestCase;

final class PdfMergerTest extends TestCase
{
    private static function pdf(int $pages): string
    {
        $mpdf = new Mpdf(['format' => 'A6']);
        for ($i = 1; $i <= $pages; $i++) {
            if ($i > 1) {
                $mpdf->AddPage();
            }
            $mpdf->WriteHTML('<p>Štítek ' . $i . '</p>');
        }
        $pdf = $mpdf->Output('', 'S');
        self::assertIsString($pdf);
        return $pdf;
    }

    public function testMergesAllPagesInOrder(): void
    {
        $merged = PdfMerger::merge([self::pdf(1), self::pdf(2)]);

        self::assertStringStartsWith('%PDF', $merged);
        self::assertSame(3, preg_match_all('~/Type\s*/Page\b~', $merged));
    }

    public function testSinglePdfIsReturnedUnchanged(): void
    {
        self::assertSame('%PDF-1.4 x', PdfMerger::merge(['%PDF-1.4 x']));
    }

    public function testNothingToMerge(): void
    {
        $this->expectException(ShippingException::class);
        PdfMerger::merge([]);
    }
}
