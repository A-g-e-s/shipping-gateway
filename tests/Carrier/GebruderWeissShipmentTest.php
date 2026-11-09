<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Tests\Carrier;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\Shipment\Dimensions;
use Ages\ShippingGateway\Common\Shipment\Parcel;
use Ages\ShippingGateway\Common\Shipment\ParcelType;
use Ages\ShippingGateway\Common\ShipmentAlreadyExistsException;
use Ages\ShippingGateway\GebruderWeiss\Config\GebruderWeissConfig;
use Ages\ShippingGateway\GebruderWeiss\Handler\GebruderWeissShipmentHandler;
use Ages\ShippingGateway\Tests\MockHttp;
use GuzzleHttp\Psr7\Response;

final class GebruderWeissShipmentTest extends CarrierTestCase
{
    private static function handler(MockHttp $mock): GebruderWeissShipmentHandler
    {
        return new GebruderWeissShipmentHandler(new GebruderWeissConfig('id', 'secret', 7001, 'CZ1', self::pickup(), '0123456'), $mock->client);
    }

    private static function token(): Response
    {
        return self::json(['access_token' => 'token', 'expires_in' => 300]);
    }

    private static function isValidSscc(string $sscc): bool
    {
        $sum = 0;
        for ($i = 0; $i < 17; $i++) {
            $sum += (int) $sscc[16 - $i] * ($i % 2 === 0 ? 3 : 1);
        }
        return strlen($sscc) === 18 && ctype_digit($sscc) && (int) $sscc[17] === (10 - $sum % 10) % 10;
    }

    public function testTransportOrderHasSsccPerParcelAndLabelsInFirstParcel(): void
    {
        $mock = new MockHttp([self::token(), new Response(202)]);
        $request = self::request(parcels: [
            new Parcel(120.4, ParcelType::PalletEur, new Dimensions(120, 80, 150)),
            new Parcel(3.6),
        ], cod: 2500.0);

        $labels = self::handler($mock)->createShipment($request);

        self::assertSame('https://my.api.gw-world.com/transport/transport-order/2.0.0/transport-order', (string) $mock->request(1)->getUri());
        $order = self::arr(self::body($mock, 1), 'transportOrder');
        self::assertSame([7001, 'OBJ123', 'CZ1'], [$order['customerId'], $order['customerReference'], $order['branchCode']]);
        self::assertSame(['SHIPPER', 'CONSIGNEE', 'FREIGHT_PAYER'], self::pluck($order, 'transportAddress', 'addressType'));
        self::assertSame([['name' => 'Jan', 'surname' => 'Novák', 'email' => 'jan@example.com', 'phone' => '777123456']], self::at($order, 'transportAddress.1.contacts'));
        self::assertSame([['qualifier' => 'COD_CASH', 'reference' => 'VS-2024/77']], self::at($order, 'transportRequirements.textKeys'));
        self::assertSame([['qualifier' => 'CASH_ON_DELIVERY', 'amount' => 2500, 'currency' => 'CZK']], $order['values']);

        self::assertCount(2, self::arr($order, 'goodsItems'));
        self::assertSame(['EUP', 'BAL'], self::pluck($order, 'goodsItems', 'packageType'));
        self::assertSame(['SSCC', 'SSCC'], self::pluck($order, 'goodsItems', 'packItems.0.barcode.barcodeType'));
        $barcodes = array_map(static fn (mixed $barcode): string => is_string($barcode) ? $barcode : '', self::pluck($order, 'goodsItems', 'packItems.0.barcode.barcode'));
        foreach ($barcodes as $barcode) {
            self::assertStringStartsWith('000123456', $barcode);
            self::assertTrue(self::isValidSscc(substr($barcode, 2)), $barcode . ' has invalid check digit');
        }
        self::assertNotSame($barcodes[0], $barcodes[1]);
        self::assertSame(120, self::at($order, 'goodsItems.0.measurements.0.measure'));
        self::assertSame(['length' => 1.2, 'width' => 0.8, 'height' => 1.5, 'dimensionUnit' => 'MTR'], self::at($order, 'goodsItems.0.packItems.0.dimension'));

        self::assertCount(2, $labels);
        self::assertSame([Carrier::GebruderWeiss, 'OBJ123'], [$labels[0]->carrier, $labels[0]->trackingNumber]);
        self::assertStringStartsWith('%PDF', $labels[0]->labelPdf);
        self::assertSame('', $labels[1]->labelPdf);

        // stejná reference a pořadí balíku → stejné SSCC (opakované odeslání)
        $again = new MockHttp([self::token(), new Response(202)]);
        self::handler($again)->createShipment($request);
        self::assertSame($barcodes, self::pluck(self::body($again, 1), 'transportOrder.goodsItems', 'packItems.0.barcode.barcode'));
    }

    public function testConflictCarriesLabelsOfExistingOrder(): void
    {
        $mock = new MockHttp([self::token(), new Response(409, [], '{"message":"exists"}')]);

        try {
            self::handler($mock)->createShipment(self::request(parcels: [new Parcel(1), new Parcel(2)]));
            self::fail('Expected ShipmentAlreadyExistsException');
        } catch (ShipmentAlreadyExistsException $e) {
            self::assertCount(2, $e->labels);
            self::assertStringStartsWith('%PDF', $e->labels[0]->labelPdf);
            self::assertSame('OBJ123', $e->labels[1]->trackingNumber);
        }
    }

    public function testTrackingSearchesOrderAndMapsDeliveryAndDamage(): void
    {
        $event = static fn (string $code, string $date, string $text, ?string $country, ?string $myGw = null): array => [
            'eventCode' => $code,
            'eventDateTime' => $date,
            'myGwStatusCode' => $myGw,
            'eventDescription' => ['translationResolved' => ['text' => $text]],
            'location' => ['locationInformation' => 'Depo ' . $code, 'countryCode' => $country],
        ];
        $mock = new MockHttp([
            self::token(),
            self::json(['orderStatusList' => [['orderReferenced' => ['orderId' => 'ORD-1']]]]),
            self::json(['statusHistory' => [
                $event('100', '2024-01-01T10:00:00Z', 'Převzato', null),
                $event('200', '2024-01-02T10:00:00Z', 'Problém s kusem', 'AT', 'CRITICAL') + ['eventReasonCode' => ['', 'R7']],
                $event('*DSC*810', '2024-01-03T10:00:00Z', 'Status 810', 'CZ'),
                $event('300', '2024-01-04T10:00:00Z', 'Doručeno', 'CZ', 'DELIVERED'),
                ['eventCode' => 'bez data'],
            ]]),
        ]);

        $tracking = self::handler($mock)->getParcelTracking('OBJ123', new \DateTimeImmutable('2024-01-01 15:00'));

        self::assertNotNull($tracking);
        parse_str($mock->request(1)->getUri()->getQuery(), $search);
        self::assertSame(['7001', 'OBJ123', '2024-01-01', '2024-01-09'], [$search['customerId'], $search['reference'], $search['dateFrom'], $search['dateTo']]);
        self::assertStringEndsWith('/orders/ORD-1/status', $mock->request(2)->getUri()->getPath());

        self::assertSame([
            ['100', false, false, null],
            ['CRITICAL', false, true, 'CRITICAL | R7'],
            ['*DSC*810', true, false, null],
            ['DELIVERED', true, false, 'DELIVERED'],
        ], self::statuses($tracking));
        self::assertTrue($tracking->delivered);
        self::assertTrue($tracking->damaged);
        self::assertEquals(new \DateTimeImmutable('2024-01-04T10:00:00Z'), $tracking->deliveredDate);
        self::assertSame('AT', $tracking->deliveryCountryCode);
        self::assertSame('OBJ123', $tracking->parcelNumber);
        self::assertSame('Depo 200', $tracking->parcelStatuses[1]->depotCity);
    }
}
