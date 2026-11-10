<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Tests\OneByAllegro;

use Ages\ShippingGateway\OneByAllegro\Widget\OneWidget;
use Ages\ShippingGateway\OneByAllegro\Widget\WidgetPointType;
use PHPUnit\Framework\TestCase;

final class OneWidgetTest extends TestCase
{
    public function testIframeUrlMatchesDocumentationExample(): void
    {
        self::assertSame('https://widget.wedo.cz/v5/widget-content?selectMode=postMessage', OneWidget::iframeUrl());
    }

    public function testIframeUrlWithOptions(): void
    {
        $url = OneWidget::iframeUrl(
            fixedType: WidgetPointType::Box,
            country: 'SK',
            disableBoxTypes: ['alzabox', 'OXBOX', 'alzabox'],
            disablePointTypes: ['sazka'],
            cancelMessage: true,
        );

        self::assertSame(
            'https://widget.wedo.cz/v5/widget-content?selectMode=postMessage&cancelMessage=true&fixedType=Box'
            . '&disableBoxType=alzabox%2Coxbox&disablePointType=sazka&country=sk',
            $url,
        );
    }

    public function testRedirectUrlEscapesCallbackUrls(): void
    {
        $url = OneWidget::redirectUrl(
            'https://www.wolfio.cz/kosik/doprava?psd=allegro&selected=',
            'https://www.wolfio.cz/kosik/doprava',
            fixedType: WidgetPointType::Point,
        );

        self::assertStringStartsWith(OneWidget::BaseUrl . '?selectMode=redirect&selectTarget=https%3A%2F%2Fwww.wolfio.cz%2F', $url);
        $query = (string) parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);
        self::assertSame('https://www.wolfio.cz/kosik/doprava?psd=allegro&selected=', $params['selectTarget']);
        self::assertSame('https://www.wolfio.cz/kosik/doprava', $params['cancelUrl']);
        self::assertSame('Point', $params['fixedType']);
    }

    public function testRedirectUrlRequiresAbsoluteUrls(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OneWidget::redirectUrl('/kosik', 'https://www.wolfio.cz/kosik');
    }

    public function testRejectsUnknownCountry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OneWidget::iframeUrl(country: 'PL');
    }

    public function testRejectsUnknownBoxType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OneWidget::iframeUrl(disableBoxTypes: ['zbox']);
    }
}
