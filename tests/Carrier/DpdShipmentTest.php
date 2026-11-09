<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Tests\Carrier;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\Shipment\Parcel;
use Ages\ShippingGateway\Dpd\Config\DpdConfig;
use Ages\ShippingGateway\Dpd\Handler\DpdShipmentHandler;
use Ages\ShippingGateway\Tests\MockHttp;
use GuzzleHttp\Psr7\Response;

final class DpdShipmentTest extends CarrierTestCase
{
    private static function handler(MockHttp $mock, ?int $senderAddressId = null): DpdShipmentHandler
    {
        return new DpdShipmentHandler(new DpdConfig('api-key', '10012345678', self::pickup(), $senderAddressId), $mock->client);
    }

    /**
     * @param string[] $numbers
     */
    private static function createdMock(array $numbers): MockHttp
    {
        return new MockHttp([
            self::json([['parcels' => array_map(static fn (string $n): array => ['parcelNumbers' => ['main' => $n]], $numbers)]]),
            new Response(200, ['Content-Type' => 'application/pdf'], base64_encode(self::Pdf)),
        ]);
    }

    public function testShipmentWithCodInCentsPickupPointAndRegisteredSender(): void
    {
        $mock = self::createdMock(['13800000000001', '13800000000002']);

        $labels = self::handler($mock, 4242)->createShipment(
            self::request(parcels: [new Parcel(1.25), new Parcel(0.0004, reference: 'BOX-2')], cod: 1234.56, parcelShopCode: ' CZ12345 '),
        );

        self::assertSame('https://geoapi.dpd.cz/v1/shipments', (string) $mock->request(0)->getUri());
        self::assertSame('api-key', $mock->request(0)->getHeaderLine('x-api-key'));
        $shipment = self::arr(self::body($mock, 0), '0');
        self::assertSame(['dsw' => '10012345678'], $shipment['customer']);
        self::assertSame(['it4emId' => 4242], $shipment['sender']);
        self::assertSame([
            'notification' => true,
            'pickupPoint' => 'CZ12345',
            'cashOnDelivery' => ['amountCents' => 123456, 'currency' => 'CZK', 'payment' => 'CashOrCard', 'variableSymbol' => '202477'],
        ], $shipment['services']);
        self::assertSame([
            ['weightGrams' => 1250, 'references' => ['ref1' => 'OBJ123']],
            ['weightGrams' => 1, 'references' => ['ref1' => 'OBJ123', 'ref2' => 'BOX-2']],
        ], $shipment['parcels']);
        self::assertSame(['street' => 'Dlouhá', 'houseNumber' => '5', 'postalCode' => '60200', 'city' => 'Brno', 'country' => ['isoAlpha2' => 'CZ']],
            self::at($shipment, 'receiver.address'));
        self::assertSame(['person' => 'Jan Novák', 'phone' => '+420777123456', 'email' => 'jan@example.com'], self::at($shipment, 'receiver.info.contact'));

        self::assertSame([['parcelNumber' => '13800000000001'], ['parcelNumber' => '13800000000002']], self::at(self::body($mock, 1), 'parcels'));
        self::assertSame(['13800000000001', '13800000000002'], array_map(static fn ($l) => $l->trackingNumber, $labels));
        self::assertSame(Carrier::Dpd, $labels[1]->carrier);
        self::assertSame(self::Pdf, $labels[1]->labelPdf);
    }

    public function testSenderFromPickupAddressWithoutRegisteredAddress(): void
    {
        $mock = self::createdMock(['13800000000001']);

        self::handler($mock)->createShipment(self::request());

        $shipment = self::arr(self::body($mock, 0), '0');
        self::assertSame([
            'info' => ['name1' => 'Wolfio s.r.o.', 'contact' => ['phone' => '+420777000000', 'email' => 'info@example.com']],
            'address' => ['street' => 'Hlavní', 'houseNumber' => '12', 'postalCode' => '11000', 'city' => 'Praha', 'country' => ['isoAlpha2' => 'CZ']],
        ], $shipment['sender']);
        self::assertSame(['notification' => true], $shipment['services']);
    }

    public function testTrackingSortsEventsAndDetectsDeliveryAndDamage(): void
    {
        $mock = new MockHttp([self::json([
            'parcelInfo' => ['parcelNumber' => '13800000000001', 'receiver' => ['address' => ['country' => ['isoAlpha2' => 'SK']]]],
            'parcelEvents' => [
                ['status' => ['statusCode' => '13', 'description' => 'Doručeno'], 'createdAt' => '2024-06-03T10:00:00Z', 'additionalInfo' => 'Novák'],
                ['status' => ['statusCode' => '01', 'description' => 'Převzato od odesílatele'], 'createdAt' => '2024-06-01T10:00:00Z'],
                ['status' => ['statusCode' => '05', 'description' => 'Zásilka poškozena'], 'createdAt' => '2024-06-02T10:00:00Z'],
                ['status' => ['statusCode' => 23, 'description' => ''], 'createdAt' => '2024-06-04T10:00:00Z'],
            ],
        ])]);

        $tracking = self::handler($mock)->getParcelTracking('13800000000001');

        self::assertNotNull($tracking);
        self::assertSame([
            ['01', false, false, null],
            ['05', false, true, null],
            ['13', true, false, 'Doručeno'],
            ['23', true, false, 'Doručeno'],
        ], self::statuses($tracking));
        self::assertSame('23', $tracking->parcelStatuses[3]->statusDescription);
        self::assertSame('Novák', $tracking->parcelStatuses[2]->statusInfo);
        self::assertTrue($tracking->delivered);
        self::assertTrue($tracking->damaged);
        self::assertEquals(new \DateTimeImmutable('2024-06-04T10:00:00Z'), $tracking->deliveredDate);
        self::assertSame('SK', $tracking->deliveryCountryCode);
        self::assertSame(0.0, $tracking->weight);
    }
}
