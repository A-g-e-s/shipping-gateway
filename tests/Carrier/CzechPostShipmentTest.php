<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Tests\Carrier;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\Shipment\Parcel;
use Ages\ShippingGateway\CzechPost\Config\CzechPostConfig;
use Ages\ShippingGateway\CzechPost\CzechPostException;
use Ages\ShippingGateway\CzechPost\Handler\CzechPostShipmentHandler;
use Ages\ShippingGateway\Tests\MockHttp;
use GuzzleHttp\Psr7\Response;

final class CzechPostShipmentTest extends CarrierTestCase
{
    private static function handler(MockHttp $mock): CzechPostShipmentHandler
    {
        return new CzechPostShipmentHandler(new CzechPostConfig('token', 'secret', 1, 'CUST1', '11000', 3, '/cert.pem'), $mock->client);
    }

    public function testMultipartShipmentWithCod(): void
    {
        $mock = new MockHttp([self::json(['responseHeader' => [
            'resultHeader' => ['responseCode' => 1],
            'responsePrintParams' => ['file' => base64_encode(self::Pdf)],
            'resultParcelData' => [['parcelCode' => 'DR0001'], ['recordNumber' => 'DR0002']],
        ]])]);

        $labels = self::handler($mock)->createShipment(self::request(parcels: [new Parcel(2.5), new Parcel(1)], cod: 990.0));

        self::assertSame('https://b2b.postaonline.cz:444/restservices/ZSKService/v1/parcelService', (string) $mock->request(0)->getUri());
        self::assertSame('token', $mock->request(0)->getHeaderLine('Api-Token'));
        self::assertSame(hash('sha256', (string) $mock->request(0)->getBody()), $mock->request(0)->getHeaderLine('Authorization-Content-SHA256'));

        $body = self::body($mock, 0);
        $header = self::arr($body, 'parcelServiceHeader.parcelServiceHeaderCom');
        self::assertSame(['CUST1', '11000', 3], [$header['customerID'], $header['postCode'], $header['locationNumber']]);
        $params = self::arr($body, 'parcelServiceData.parcelParams');
        self::assertSame(['OBJ123-1', 'DR', '2.5', 1, 2], [$params['recordID'], $params['prefixParcelCode'], $params['weight'], $params['sequenceParcel'], $params['quantityParcel']]);
        self::assertSame([990, 'CZK', 'VS-2024/77'], [$params['amount'], $params['currency'], $params['vsVoucher']]);
        self::assertSame(['L', '46', '34', '41', '70'], self::at($body, 'parcelServiceData.parcelServices'));
        self::assertSame([[
            'addParcelData' => ['recordID' => 'OBJ123-2', 'prefixParcelCode' => 'DR', 'weight' => '1', 'sequenceParcel' => 2, 'quantityParcel' => 2],
            'addParcelDataServices' => ['70', 'L'],
        ]], $body['multipartParcelData']);

        self::assertSame(['DR0001', 'DR0002'], array_map(static fn ($l) => $l->trackingNumber, $labels));
        self::assertSame(Carrier::CzechPost, $labels[0]->carrier);
        self::assertSame(self::Pdf, $labels[1]->labelPdf);
    }

    public function testErrorResponseCodeIsReported(): void
    {
        $mock = new MockHttp([self::json(['responseHeader' => ['resultHeader' => ['responseCode' => 19]]])]);

        try {
            self::handler($mock)->createShipment(self::request());
            self::fail('Expected CzechPostException');
        } catch (CzechPostException $e) {
            self::assertSame(19, $e->getCode());
            self::assertStringStartsWith('Czech Post: ', $e->getMessage());
        }
    }

    public function testTrackingReversesStatusesAndDetectsDelivery(): void
    {
        $mock = new MockHttp([self::json(['detail' => [[
            'idParcel' => 'DR0001', 'countryOfDestination' => 'CZ', 'weight' => '2.5',
            'parcelStatuses' => [
                ['id' => 91, 'date' => '2024-03-02T09:00:00+00:00', 'text' => 'Dodání zásilky.', 'name' => 'Brno 2', 'postCode' => '60200'],
                ['id' => '21', 'date' => '2024-03-01T09:00:00+00:00', 'text' => 'Podání zásilky.', 'name' => '', 'postCode' => ''],
                ['id' => '99', 'text' => 'bez data – přeskočí se'],
            ],
        ]]])]);

        $tracking = self::handler($mock)->getParcelTracking('DR0001');

        self::assertNotNull($tracking);
        self::assertSame(['parcelIds' => ['DR0001'], 'language' => 'cz'], self::body($mock, 0));
        self::assertSame([['21', false, false, null], ['91', true, false, null]], self::statuses($tracking));
        self::assertSame([null, null], [$tracking->parcelStatuses[0]->depotCity, $tracking->parcelStatuses[0]->depotCode]);
        self::assertSame(['Brno 2', '60200'], [$tracking->parcelStatuses[1]->depotCity, $tracking->parcelStatuses[1]->depotCode]);
        self::assertSame('2024-03-02 10:00 Europe/Prague', $tracking->deliveredDate?->format('Y-m-d H:i e'));
        self::assertTrue($tracking->delivered);
        self::assertFalse($tracking->damaged);
        self::assertSame(['DR0001', 'CZ', 2.5], [$tracking->parcelNumber, $tracking->deliveryCountryCode, $tracking->weight]);
    }

    public function testTrackingErrorIsNull(): void
    {
        $mock = new MockHttp([new Response(500, [], 'error')]);

        self::assertNull(self::handler($mock)->getParcelTracking('DR0001'));
    }
}
