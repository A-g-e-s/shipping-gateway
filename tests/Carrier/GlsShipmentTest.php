<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Tests\Carrier;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\Shipment\Parcel;
use Ages\ShippingGateway\Common\Shipment\ParcelType;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\Gls\Config\GlsConfig;
use Ages\ShippingGateway\Gls\Handler\GlsShipmentHandler;
use Ages\ShippingGateway\Tests\MockHttp;

final class GlsShipmentTest extends CarrierTestCase
{
    private static function handler(MockHttp $mock): GlsShipmentHandler
    {
        return new GlsShipmentHandler(new GlsConfig('user', 'secret', 5551, self::pickup()), $mock->client);
    }

    public function testPrintLabelsSendsParcelWithCodAndParcelShop(): void
    {
        $mock = new MockHttp([self::json([
            'Labels' => array_map(ord(...), str_split(self::Pdf)),
            'PrintLabelsErrorList' => [],
            'PrintLabelsInfoList' => [['ParcelNumber' => 1001], ['ParcelNumber' => 1002]],
        ])]);

        $labels = self::handler($mock)->createShipment(self::request(parcels: [new Parcel(1), new Parcel(2)], cod: 750.5, parcelShopCode: '2351-CSAPPLIE'));

        self::assertSame('https://api.mygls.cz/ParcelService.svc/json/PrintLabels', (string) $mock->request(0)->getUri());
        $body = self::body($mock, 0);
        self::assertSame('user', $body['Username']);
        self::assertSame(array_values((array) unpack('C*', hash('sha512', 'secret', true))), $body['Password']);
        $parcel = self::arr($body, 'ParcelList.0');
        self::assertSame(['5551', 'OBJ123', 2], [$parcel['ClientNumber'], $parcel['ClientReference'], $parcel['Count']]);
        self::assertSame([750.5, 'VS-2024/77', 'CZK'], [$parcel['CODAmount'], $parcel['CODReference'], $parcel['CODCurrency']]);
        self::assertContains(['Code' => 'PSD', 'PSDParameter' => ['StringValue' => '2351-CSAPPLIE']], self::arr($parcel, 'ServiceList'));
        $address = self::arr($parcel, 'DeliveryAddress');
        self::assertSame(['Jan Novák', 'Dlouhá 5', 'Brno', '60200', 'CZ'], [$address['Name'], $address['Street'], $address['City'], $address['ZipCode'], $address['CountryIsoCode']]);

        self::assertSame(['1001', '1002'], array_map(static fn ($l) => $l->trackingNumber, $labels));
        self::assertSame(Carrier::Gls, $labels[0]->carrier);
        self::assertSame(self::Pdf, $labels[1]->labelPdf);
    }

    public function testPrintLabelsErrorsAreReported(): void
    {
        $mock = new MockHttp([self::json(['PrintLabelsErrorList' => [['ErrorCode' => 14, 'ErrorDescription' => 'Invalid zip']]])]);

        $this->expectExceptionMessage('GLS: 14 (Invalid zip)');
        self::handler($mock)->createShipment(self::request());
    }

    public function testPalletIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::handler(new MockHttp())->createShipment(self::request(parcels: [new Parcel(100, ParcelType::PalletEur)]));
    }

    public function testTrackingUsesStatusCodeTable(): void
    {
        $mock = new MockHttp([self::json([
            'ParcelNumber' => 1001, 'DeliveryCountryCode' => 'CZ', 'DeliveryZipCode' => '60200', 'ClientReference' => 'OBJ123', 'Weight' => '2.5',
            'ParcelStatusList' => [
                ['StatusCode' => '51', 'StatusDate' => '/Date(1700000000000+0100)/', 'StatusDescription' => 'Data zadána', 'DepotCity' => 'Praha', 'DepotNumber' => 'CZ01'],
                ['StatusCode' => '30', 'StatusDate' => '2023-11-15T08:00:00', 'StatusDescription' => 'Poškozeno', 'StatusInfo' => 'roh'],
                ['StatusCode' => '05', 'StatusDate' => '2023-11-16T09:30:00+01:00', 'StatusDescription' => 'Doručeno', 'DepotCity' => 'Brno'],
                ['StatusCode' => '', 'StatusDate' => '', 'StatusDescription' => ''],
            ],
        ])]);

        $tracking = self::handler($mock)->getParcelTracking('1001');

        self::assertNotNull($tracking);
        self::assertSame(['ParcelNumber' => '1001', 'ReturnPOD' => true, 'LanguageIsoCode' => 'CS'], array_diff_key(self::body($mock, 0), ['Username' => 1, 'Password' => 1]));
        self::assertSame([
            ['51', false, false, 'Data zásilky byla zadána do systému GLS; zásilka zatím nebyla předána GLS.'],
            ['30', false, true, 'Příchozí zásilka je poškozená.'],
            ['05', true, false, 'Zásilka byla doručena.'],
        ], self::statuses($tracking));
        self::assertEquals(new \DateTimeImmutable('2023-11-14T23:13:20+01:00'), $tracking->parcelStatuses[0]->statusDate);
        self::assertSame('+01:00', $tracking->parcelStatuses[0]->statusDate?->getTimezone()->getName());
        self::assertSame(['Praha', 'CZ01', null], [$tracking->parcelStatuses[0]->depotCity, $tracking->parcelStatuses[0]->depotCode, $tracking->parcelStatuses[0]->statusInfo]);
        self::assertSame('roh', $tracking->parcelStatuses[1]->statusInfo);
        self::assertTrue($tracking->delivered);
        self::assertTrue($tracking->damaged);
        self::assertEquals(new \DateTimeImmutable('2023-11-16T09:30:00+01:00'), $tracking->deliveredDate);
        self::assertSame(['1001', 'CZ', 2.5], [$tracking->parcelNumber, $tracking->deliveryCountryCode, $tracking->weight]);
    }

    public function testTrackingErrorsAndUnknownParcel(): void
    {
        $mock = new MockHttp([
            self::json(['GetParcelStatusErrors' => [['ErrorCode' => 27, 'ErrorDescription' => 'Not found']]]),
            self::json(['ParcelNumber' => '']),
        ]);
        $handler = self::handler($mock);

        try {
            $handler->getParcelTracking('1');
            self::fail('Expected ShippingException');
        } catch (ShippingException $e) {
            self::assertSame('GLS: 27 (Not found)', $e->getMessage());
        }
        self::assertNull($handler->getParcelTracking('2'));
    }
}
