<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Tests\Common\Label;

use Ages\ShippingGateway\Common\Label\PdfPageFitter;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\Tests\PdfPages;
use PHPUnit\Framework\TestCase;

final class PdfPageFitterTest extends TestCase
{
    public function testEveryLabelGetsOwnThermalPage(): void
    {
        $fitted = PdfPageFitter::fit([PdfPages::create([[70, 90], [70, 90]]), PdfPages::create([[70, 90]])]);

        self::assertStringStartsWith('%PDF', $fitted);
        self::assertSame([[100.0, 150.0], [100.0, 150.0], [100.0, 150.0]], PdfPages::sizes($fitted));
    }

    public function testWideLabelGoesOnLandscapePageAndLargePageIsScaledDown(): void
    {
        $fitted = PdfPageFitter::fit([PdfPages::create([[140, 90], [210, 297]])], center: false);

        self::assertSame([[150.0, 100.0], [100.0, 150.0]], PdfPages::sizes($fitted));
    }

    public function testCustomPageSize(): void
    {
        self::assertSame([[105.0, 148.0]], PdfPages::sizes(PdfPageFitter::fit([PdfPages::create([[70, 90]])], 105, 148)));
    }

    public function testNothingToFit(): void
    {
        $this->expectException(ShippingException::class);
        PdfPageFitter::fit([]);
    }
}
