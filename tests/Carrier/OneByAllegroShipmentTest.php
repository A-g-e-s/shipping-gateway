<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Tests\Carrier;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\Shipment\Dimensions;
use Ages\ShippingGateway\Common\Shipment\Parcel;
use Ages\ShippingGateway\OneByAllegro\Config\OneByAllegroConfig;
use Ages\ShippingGateway\OneByAllegro\Handler\OneByAllegroShipmentHandler;
use Ages\ShippingGateway\Tests\MockHttp;
use GuzzleHttp\Psr7\Response;

final class OneByAllegroShipmentTest extends CarrierTestCase
{
    private static function handler(MockHttp $mock): OneByAllegroShipmentHandler
    {
        return new OneByAllegroShipmentHandler(new OneByAllegroConfig('user', 'pass', self::pickup()), $mock->client);
    }

    private static function createdMock(): MockHttp
    {
        return new MockHttp([
            self::json(['accessToken' => 'token', 'expiresIn' => 3600]),
            self::json(['shipmentId' => 'S-1', 'packages' => [['packageNumber' => 'P1'], ['packageNumber' => 'P2']]]),
            new Response(200, ['Content-Type' => 'application/pdf'], self::Pdf),
        ]);
    }

    public function testPickupPointShipmentSendsPointIdWithoutDeliveryMethod(): void
    {
        $mock = self::createdMock();

        $labels = self::handler($mock)->createShipment(self::request(
            self::recipient('SK', 'Hlavná', '12/3', '0905 123 456'),
            [new Parcel(1.5, dimensions: new Dimensions(40, 30, 20.5)), new Parcel(2)],
            cod: 49.9,
            parcelShopCode: ' SK-BOX-1 ',
        ));

        self::assertSame('https://api.onedelivery.tech/parcels/shipments', (string) $mock->request(1)->getUri());
        self::assertSame('Bearer token', $mock->request(1)->getHeaderLine('Authorization'));
        $body = self::body($mock, 1);
        self::assertSame(['service' => 'STANDARD'], $body['delivery']);
        self::assertSame([
            'firstName' => 'Jan', 'lastName' => 'Novák', 'phoneNumber' => '+421905123456', 'email' => 'jan@example.com',
            'postcode' => '60200', 'city' => 'Brno', 'street' => 'Hlavná', 'streetNumber' => '12/3', 'countryCode' => 'SK', 'pointId' => 'SK-BOX-1',
        ], $body['receiver']);
        self::assertSame([
            ['weight' => '1.5', 'length' => '40', 'width' => '30', 'height' => '20.5'],
            ['weight' => '2', 'length' => '30', 'width' => '20', 'height' => '15'],
        ], $body['packages']);
        self::assertSame(['amount' => 49.9, 'currency' => 'CZK'], $body['cod']);
        self::assertSame('VS-2024/77', $body['referenceNumber2']);
        self::assertSame('OBJ123', $body['referenceNumber1']);
        self::assertSame(['companyName' => 'Wolfio s.r.o.', 'phoneNumber' => '+420777000000', 'email' => 'info@example.com', 'postcode' => '11000',
            'city' => 'Praha', 'street' => 'Hlavní', 'streetNumber' => '12', 'countryCode' => 'CZ'], $body['sender']);

        self::assertSame(['shipmentIds' => ['S-1'], 'format' => 'PDF_A6'], self::body($mock, 2));
        self::assertCount(2, $labels);
        self::assertSame(Carrier::OneByAllegro, $labels[0]->carrier);
        self::assertSame(['S-1', 'S-1'], [$labels[0]->trackingNumber, $labels[1]->trackingNumber]);
        self::assertSame(self::Pdf, $labels[1]->labelPdf);
    }

    public function testCourierShipmentSplitsStreetAndAddsCzechPhonePrefix(): void
    {
        $mock = self::createdMock();

        self::handler($mock)->createShipment(self::request(self::recipient('CZ', 'Dlouhá 15/7a', null, '777 123 456')));

        $body = self::body($mock, 1);
        self::assertSame(['service' => 'STANDARD', 'method' => 'TO_DOOR'], $body['delivery']);
        $receiver = self::arr($body, 'receiver');
        self::assertArrayNotHasKey('pointId', $receiver);
        self::assertSame(['Dlouhá', '15/7a', '+420777123456'], [$receiver['street'], $receiver['streetNumber'], $receiver['phoneNumber']]);
        self::assertArrayNotHasKey('cod', $body);
        self::assertSame(['amount' => 1500.0, 'currency' => 'CZK'], $body['totalValue']);
    }

    public function testTrackingMapsDeliveredDamagedLostAndReturned(): void
    {
        $mock = new MockHttp([
            self::json(['accessToken' => 'token', 'expiresIn' => 3600]),
            self::json(['shipments' => [['shipmentId' => 'S-1', 'statuses' => [
                ['code' => 'DOR', 'date' => '2024-05-04T10:00:00Z', 'description' => 'Doručeno', 'place' => 'Praha'],
                ['code' => 'NAD', 'date' => '2024-05-01T10:00:00Z', 'description' => 'Nadáno'],
                ['code' => 'USZ', 'date' => '2024-05-02T10:00:00Z', 'description' => ''],
                ['code' => 'ZAG', 'date' => '2024-05-03T10:00:00Z'],
                ['code' => 'ZDN', 'date' => '2024-05-05T10:00:00Z'],
            ]]]]),
            self::json(['receiver' => ['countryCode' => 'CZ'], 'packages' => [['weight' => '1.5'], ['weight' => 2]]]),
        ]);

        $tracking = self::handler($mock)->getParcelTracking('S-1');

        self::assertNotNull($tracking);
        self::assertSame('shipmentIds=S-1', $mock->request(1)->getUri()->getQuery());
        self::assertSame([
            ['NAD', false, false, null],
            ['USZ', false, true, 'Poškozeno'],
            ['ZAG', false, true, 'Ztraceno'],
            ['DOR', true, false, 'Doručeno'],
            ['ZDN', false, false, 'Vráceno odesílateli'],
        ], self::statuses($tracking));
        self::assertSame('USZ', $tracking->parcelStatuses[1]->statusDescription);
        self::assertSame('Praha', $tracking->parcelStatuses[3]->depotCity);
        self::assertTrue($tracking->delivered);
        self::assertTrue($tracking->damaged);
        self::assertEquals(new \DateTimeImmutable('2024-05-04T10:00:00Z'), $tracking->deliveredDate);
        self::assertSame('CZ', $tracking->deliveryCountryCode);
        self::assertSame(3.5, $tracking->weight);
    }

    public function testTrackingOfUnknownShipmentIsNull(): void
    {
        $mock = new MockHttp([self::json(['accessToken' => 'token', 'expiresIn' => 3600]), self::json(['shipments' => []])]);

        self::assertNull(self::handler($mock)->getParcelTracking('S-404'));
    }
}
