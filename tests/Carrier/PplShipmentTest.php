<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Tests\Carrier;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\Shipment\Parcel;
use Ages\ShippingGateway\Common\Shipment\ShipmentRequest;
use Ages\ShippingGateway\Common\Tracking\ParcelTracking;
use Ages\ShippingGateway\Ppl\Config\PplConfig;
use Ages\ShippingGateway\Ppl\Handler\PplShipmentHandler;
use Ages\ShippingGateway\Tests\MockHttp;
use PHPUnit\Framework\Attributes\DataProvider;

final class PplShipmentTest extends CarrierTestCase
{
    private const string LabelUrl = 'https://api.dhl.com/ecs/ppl/myapi2/label/abc';

    private static function handler(MockHttp $mock): PplShipmentHandler
    {
        return new PplShipmentHandler(new PplConfig('id', 'secret', self::pickup()), $mock->client);
    }

    /**
     * Token, založení dávky, stav dávky (hotovo), štítek.
     *
     * @param list<array<string, mixed>> $items položky stavu dávky
     * @param array<string, mixed> $status další pole stavu dávky
     */
    private static function batchMock(array $items, array $status = []): MockHttp
    {
        return new MockHttp([
            self::json(['access_token' => 'token', 'expires_in' => 1800]),
            new \GuzzleHttp\Psr7\Response(201, ['Location' => 'https://api.dhl.com/ecs/ppl/myapi2/shipment/batch/B-1']),
            self::json(['items' => $items] + $status),
            new \GuzzleHttp\Psr7\Response(200, [], self::Pdf),
        ]);
    }

    /**
     * @return array<string, array{string, ?string, ?float, string}>
     */
    public static function products(): array
    {
        return [
            'CZ na adresu' => ['CZ', null, null, 'PRIV'],
            'CZ na adresu s dobírkou' => ['CZ', null, 500.0, 'PRID'],
            'CZ výdejní místo' => ['CZ', 'KM1234', null, 'SMAR'],
            'CZ výdejní místo s dobírkou' => ['CZ', 'KM1234', 500.0, 'SMAD'],
            'export na adresu' => ['SK', null, null, 'CONN'],
            'export na adresu s dobírkou' => ['SK', null, 500.0, 'COND'],
            'export výdejní místo' => ['DE', 'DE-123', null, 'SMEU'],
            'export výdejní místo s dobírkou' => ['SK', 'SK-123', 500.0, 'SMED'],
        ];
    }

    #[DataProvider('products')]
    public function testProductByCountryPickupPointAndCod(string $country, ?string $parcelShop, ?float $cod, string $product): void
    {
        $mock = self::batchMock([['importState' => 'Complete', 'shipmentNumber' => '40001', 'labelUrl' => self::LabelUrl]]);

        $labels = self::handler($mock)->createShipment(self::request(self::recipient($country), cod: $cod, parcelShopCode: $parcelShop));

        $shipment = self::arr(self::body($mock, 1), 'shipments.0');
        self::assertSame($product, $shipment['productType']);
        self::assertSame('OBJ123', $shipment['referenceId']);
        self::assertSame([['externalNumber' => 'OBJ123', 'code' => 'CUST']], $shipment['externalNumbers']);
        self::assertSame($parcelShop !== null ? ['parcelShopCode' => $parcelShop] : null, $shipment['specificDelivery'] ?? null);
        if ($cod !== null) {
            self::assertSame(['codPrice' => 500, 'codCurrency' => 'CZK', 'codVarSym' => '202477'], $shipment['cashOnDelivery']);
        } else {
            self::assertNull($shipment['cashOnDelivery']);
        }

        self::assertCount(1, $labels);
        self::assertSame(Carrier::Ppl, $labels[0]->carrier);
        self::assertSame('40001', $labels[0]->trackingNumber);
        self::assertSame(self::Pdf, $labels[0]->labelPdf);
        self::assertSame('https://api.dhl.com/ecs/ppl/myapi2/shipment/batch/B-1', (string) $mock->request(2)->getUri());
        self::assertSame(self::LabelUrl, (string) $mock->request(3)->getUri());
    }

    public function testDomesticMultiParcelIsOneShipmentSet(): void
    {
        $mock = self::batchMock([[
            'importState' => 'Complete', 'shipmentNumber' => '40001', 'labelUrl' => self::LabelUrl,
            'relatedItems' => [['importState' => 'Complete', 'shipmentNumber' => '40002']],
        ]]);

        $labels = self::handler($mock)->createShipment(self::request(parcels: [new Parcel(1), new Parcel(2)], cod: 999.0));

        $shipments = self::arr(self::body($mock, 1), 'shipments');
        self::assertCount(1, $shipments);
        self::assertSame('PRID', self::at($shipments, '0.productType'));
        self::assertSame(2, self::at($shipments, '0.shipmentSet.numberOfShipments'));
        self::assertSame(999, self::at($shipments, '0.cashOnDelivery.codPrice'));
        self::assertSame('Jan Novák', self::at($shipments, '0.recipient.name'));
        self::assertSame(['40001', '40002'], array_map(static fn ($l) => $l->trackingNumber, $labels));
    }

    public function testExportWithCodOnThreeParcelsIsSplitIntoThreeAsciiShipments(): void
    {
        $mock = self::batchMock([
            ['importState' => 'Complete', 'shipmentNumber' => '50001'],
            ['importState' => 'Complete', 'shipmentNumber' => '50002'],
            ['importState' => 'Complete', 'shipmentNumber' => '50003'],
        ], ['completeLabel' => ['labelUrls' => [self::LabelUrl]]]);

        $request = new ShipmentRequest(
            'OBJ9',
            self::recipient('SK', 'Hlavná 5', null, '905123456', 'Ľubomír', 'Šťastný', 'Košice'),
            [new Parcel(1), new Parcel(2), new Parcel(3)],
            new \Ages\ShippingGateway\Common\Shipment\ShipmentValue(100.01, 'EUR'),
            new \Ages\ShippingGateway\Common\Shipment\CashOnDelivery(100.01, '2024', 'eur'),
        );
        $labels = self::handler($mock)->createShipment($request);

        $body = self::body($mock, 1);
        self::assertSame(['OBJ9-1', 'OBJ9-2', 'OBJ9-3'], self::pluck($body, 'shipments', 'referenceId'));
        self::assertSame([33.33, 33.33, 33.35], self::pluck($body, 'shipments', 'cashOnDelivery.codPrice'));
        foreach (self::arr($body, 'shipments') as $shipment) {
            self::assertSame('COND', self::at($shipment, 'productType'));
            self::assertSame('EUR', self::at($shipment, 'cashOnDelivery.codCurrency'));
            self::assertSame(1, self::at($shipment, 'shipmentSet.numberOfShipments'));
            self::assertSame([['externalNumber' => 'OBJ9', 'code' => 'CUST']], self::at($shipment, 'externalNumbers'));
            self::assertSame(['name' => 'Lubomir Stastny', 'street' => 'Hlavna 5', 'city' => 'Kosice', 'zipCode' => '60200', 'country' => 'SK'],
                array_intersect_key(self::arr($shipment, 'recipient'), array_flip(['name', 'street', 'city', 'zipCode', 'country'])));
            self::assertSame('Hlavni 12', self::at($shipment, 'sender.street'));
        }

        // jediný štítek celé dávky (completeLabel) → bez slučování PDF
        self::assertSame(4, $mock->count());
        self::assertSame(['50001', '50002', '50003'], array_map(static fn ($l) => $l->trackingNumber, $labels));
        self::assertSame(self::Pdf, $labels[2]->labelPdf);
    }

    public function testTrackingMapsEventsOldestFirstAndDelivery(): void
    {
        $mock = new MockHttp([
            self::json(['access_token' => 'token', 'expires_in' => 1800]),
            self::json([[
                'shipmentNumber' => '40001', 'shipmentState' => 'Delivered', 'depot' => '07',
                'deliveryFeature' => ['delivDate' => '2024-01-02T10:00:00+01:00'],
                'shipmentWeightInfo' => ['weight' => 1.2],
                'recipient' => ['country' => 'CZ', 'zipCode' => '60200'],
                'externalNumbers' => [['externalNumber' => 'OBJ123', 'code' => 'CUST']],
                'trackAndTrace' => ['events' => [
                    ['code' => 'Delivered', 'eventDate' => '2024-01-02T11:00:00+01:00', 'name' => 'Doručeno'],
                    ['code' => 'Created', 'eventDate' => '2024-01-01T08:00:00+01:00', 'name' => 'Vytvořeno'],
                ]],
            ]]),
        ]);

        $tracking = self::handler($mock)->getParcelTracking('40001');

        self::assertNotNull($tracking);
        self::assertSame(['ShipmentNumbers' => ['40001'], 'Limit' => '10', 'Offset' => '0'], self::query($mock));
        self::assertSame([['Created', false, false, null], ['Delivered', true, false, 'Doručeno']], self::statuses($tracking));
        self::assertTrue($tracking->delivered);
        self::assertFalse($tracking->damaged);
        self::assertEquals(new \DateTimeImmutable('2024-01-02T11:00:00+01:00'), $tracking->deliveredDate);
        self::assertSame('CZ', $tracking->deliveryCountryCode);
        self::assertSame(1.2, $tracking->weight);
        self::assertSame('07', $tracking->parcelStatuses[0]->depotCode);
        self::assertInstanceOf(ParcelTracking::class, $tracking);
        self::assertSame(['60200', 'OBJ123'], [$tracking->deliveryZipCode, $tracking->clientReference]);
    }

    /**
     * @return array<mixed>
     */
    private static function query(MockHttp $mock): array
    {
        parse_str(urldecode($mock->request(1)->getUri()->getQuery()), $query);
        // Guzzle posílá pole jako ShipmentNumbers[0]=…
        return $query;
    }
}
