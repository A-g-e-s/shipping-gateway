<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Tests\Carrier;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\Http\HttpStatusException;
use Ages\ShippingGateway\Common\Shipment\Dimensions;
use Ages\ShippingGateway\Common\Shipment\Parcel;
use Ages\ShippingGateway\Common\Shipment\ParcelType;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\Common\Tracking\ParcelTracking;
use Ages\ShippingGateway\Tests\MockHttp;
use Ages\ShippingGateway\Tests\PdfPages;
use Ages\ShippingGateway\Toptrans\Config\ToptransConfig;
use Ages\ShippingGateway\Toptrans\Handler\ToptransShipmentHandler;
use Ages\ShippingGateway\Toptrans\Values\LabelFormat;
use Ages\ShippingGateway\Toptrans\Values\PackType;
use Ages\ShippingGateway\Toptrans\Values\Term;
use GuzzleHttp\Psr7\Response;

final class ToptransShipmentTest extends CarrierTestCase
{
    private const int OrderId = 123456;
    private const int BatchId = 7059753;
    private const string OrderNumber = '17720018911';

    private static function handler(MockHttp $mock, ?ToptransConfig $config = null): ToptransShipmentHandler
    {
        return new ToptransShipmentHandler($config ?? new ToptransConfig('user', 'secret', labelFormat: LabelFormat::A4), $mock->client);
    }

    private static function ok(mixed $data): Response
    {
        return self::json(['status' => 'ok', 'data' => $data, 'errors' => []]);
    }

    private static function sent(): Response
    {
        return self::ok([
            'numOk' => 1, 'numObj' => 1, 'numNahlaska' => 0, 'numSkipped' => 0, 'numErrors' => 0,
            'orderListObj' => self::BatchId,
            'message' => 'Počet odeslaných objednávek: 1',
            'files' => [
                'zasilatelsky-prikaz' => ['filename' => 'zasilatelsky-prikaz-1', 'data' => base64_encode('%PDF-1.4 prikaz')],
                'soupiska' => ['filename' => 'soupiska-1', 'data' => base64_encode('%PDF-1.4 soupiska')],
                'stitky' => ['filename' => 'stitky-1', 'data' => base64_encode(self::Pdf)],
            ],
        ]);
    }

    private static function archive(): Response
    {
        return self::ok([
            ['id' => 1, 'order_ids' => [1], 'order_numbers' => [17720000001]],
            ['id' => self::BatchId, 'order_ids' => [self::OrderId - 1, self::OrderId], 'order_numbers' => [17720018910, (int) self::OrderNumber]],
        ]);
    }

    public function testShipmentSavesSendsAndReturnsOrderNumberWithA4Labels(): void
    {
        $mock = new MockHttp([self::ok(['id' => self::OrderId]), self::sent(), self::archive()]);

        $labels = self::handler($mock, new ToptransConfig('user', 'secret', labelFormat: LabelFormat::A4, codBankAccount: '19-2000145399/0800'))->createShipment(self::request(
            parcels: [new Parcel(12.5, dimensions: new Dimensions(60, 40, 30)), new Parcel(8.25, ParcelType::PackageOversize, new Dimensions(120, 50, 50), 'KRMIVO-2')],
            cod: 1234.567,
        ));

        self::assertSame(3, $mock->count());
        self::assertSame('https://zp.toptrans.cz/api/json/order/save/', (string) $mock->request(0)->getUri());
        self::assertSame('POST', $mock->request(0)->getMethod());
        self::assertSame(['user', 'secret'], $mock->options(0)['auth']);

        $order = self::body($mock, 0);
        self::assertSame(1, $order['strict']);
        self::assertSame(Term::Standard->value, $order['term_id']);
        self::assertSame('OBJ123', $order['label']);
        self::assertSame(1, $order['loading_select']);
        self::assertSame(1, $order['aviso_sms']);
        self::assertSame(0, $order['discharge_aviso']);
        self::assertSame(20.75, $order['kg']);
        self::assertSame(1, $order['oversize']);
        self::assertSame(0.37, $order['m3']);
        self::assertArrayNotHasKey('num_epals', $order);
        self::assertSame('Poznámka', $order['note_discharge']);
        self::assertSame([
            'name' => 'Jan Novák',
            'first_name' => 'Jan',
            'last_name' => 'Novák',
            'phone' => '+420777123456',
            'email' => 'jan@example.com',
            'address' => ['city' => 'Brno', 'street' => 'Dlouhá', 'house_num' => '5', 'zip' => '60200'],
        ], $order['discharge']);
        self::assertSame([
            ['pack_id' => PackType::Parcel->value, 'quantity' => 1, 'dimensions_d' => 60, 'dimensions_s' => 40, 'dimensions_v' => 30],
            ['pack_id' => PackType::Parcel->value, 'quantity' => 1, 'description' => 'KRMIVO-2', 'dimensions_d' => 120, 'dimensions_s' => 50, 'dimensions_v' => 50],
        ], $order['packs']);
        self::assertSame(1, $order['cash_on_delivery_type']);
        self::assertSame(1234.57, $order['cash_on_delivery_price']);
        self::assertSame(1, $order['cash_on_delivery_price_cur_id']);
        self::assertSame('19', $order['cash_on_delivery_account1']);
        self::assertSame('2000145399', $order['cash_on_delivery_account2']);
        self::assertSame('0800', $order['cash_on_delivery_bank']);
        self::assertSame('202477', $order['var_symbol']);
        self::assertArrayNotHasKey('order_value', $order);

        self::assertSame('https://zp.toptrans.cz/api/json/order/send/', (string) $mock->request(1)->getUri());
        self::assertSame(['ids' => [self::OrderId], 'options' => ['position' => 0]], self::body($mock, 1));

        self::assertSame('https://zp.toptrans.cz/api/json/order/archive/', (string) $mock->request(2)->getUri());
        $archive = self::body($mock, 2);
        self::assertSame(100, $archive['limit']);
        self::assertArrayHasKey('dateFrom', $archive);

        self::assertCount(1, $labels);
        self::assertSame(Carrier::Toptrans, $labels[0]->carrier);
        self::assertSame(self::OrderNumber, $labels[0]->trackingNumber);
        self::assertSame(self::Pdf, $labels[0]->labelPdf);
    }

    public function testPalletsPickupBranchLoadingAddressDeclaredValueAndZebraLabels(): void
    {
        $mock = new MockHttp([
            self::ok(['id' => self::OrderId]),
            self::sent(),
            new Response(200, ['Content-Type' => 'application/pdf'], self::Pdf),
            self::archive(),
        ]);
        $config = new ToptransConfig(
            'user',
            'secret',
            pickupAddress: self::pickup(),
            term: Term::TopPrivat,
            packagePack: PackType::Bag,
            packDescription: 'Krmivo pro psy',
            labelFormat: LabelFormat::Zebra,
            phoneAdvice: true,
            declareValue: true,
        );

        $recipient = self::recipient('SK', 'Hlavná 12/3', phone: '0905123456');
        $labels = self::handler($mock, $config)->createShipment(self::request(
            recipient: $recipient,
            parcels: [new Parcel(300), new Parcel(250, ParcelType::PalletEur), new Parcel(120, ParcelType::PalletHalf), new Parcel(90, ParcelType::PalletOneWay)],
            parcelShopCode: ' 450 ',
        ));

        $order = self::body($mock, 0);
        self::assertSame(Term::PersonalPickup->value, $order['term_id']);
        self::assertSame('450', $order['discharge_personal_branch_id']);
        self::assertSame(1, $order['discharge_aviso']);
        self::assertSame(2, $order['loading_select']);
        self::assertSame([
            'name' => 'Wolfio s.r.o.',
            'phone' => '+420777000000',
            'email' => 'info@example.com',
            'address' => ['city' => 'Praha', 'street' => 'Hlavní', 'house_num' => '12', 'zip' => '11000'],
        ], $order['loading']);
        self::assertSame('+421905123456', self::at($order, 'discharge.phone'));
        self::assertSame(['city' => 'Brno', 'street' => 'Hlavná', 'house_num' => '12/3', 'zip' => '60200'], self::at($order, 'discharge.address'));
        self::assertSame([PackType::Bag->value, PackType::EurPallet->value, PackType::HalfPallet->value, PackType::Pallet->value], self::pluck($order, 'packs', 'pack_id'));
        self::assertSame(['Krmivo pro psy', 'Krmivo pro psy', 'Krmivo pro psy', 'Krmivo pro psy'], self::pluck($order, 'packs', 'description'));
        self::assertSame(1, $order['num_epals']);
        self::assertSame(760, $order['kg']);
        self::assertArrayNotHasKey('oversize', $order);
        self::assertArrayNotHasKey('m3', $order);
        self::assertArrayNotHasKey('cash_on_delivery_type', $order);
        self::assertSame(1500, $order['order_value']);
        self::assertSame(1, $order['order_value_currency_id']);

        self::assertSame('https://zp.toptrans.cz/api/json/order/print-zebra-labels/', (string) $mock->request(2)->getUri());
        self::assertSame(['id' => self::BatchId], self::body($mock, 2));
        self::assertSame(self::Pdf, $labels[0]->labelPdf);
        self::assertSame(self::OrderNumber, $labels[0]->trackingNumber);
    }

    public function testDefaultThermalLabelPutsEachZebraLabelOnOwnPage100x150WithoutDocuments(): void
    {
        $zebra = PdfPages::create([[70, 90], [70, 90]]);
        $mock = new MockHttp([
            self::ok(['id' => self::OrderId]),
            self::sent(),
            new Response(200, ['Content-Type' => 'application/pdf'], $zebra),
            self::archive(),
        ]);

        $labels = self::handler($mock, new ToptransConfig('user', 'secret', attachDocuments: true))
            ->createShipment(self::request(parcels: [new Parcel(5), new Parcel(6)]));

        self::assertSame(LabelFormat::Thermal100x150, new ToptransConfig('u', 'p')->labelFormat);
        self::assertSame(4, $mock->count());
        self::assertSame('https://zp.toptrans.cz/api/json/order/print-zebra-labels/', (string) $mock->request(2)->getUri());
        self::assertSame(['id' => self::BatchId], self::body($mock, 2));
        // dva kusy = dvě stránky 100 × 150, zasilatelský příkaz ani soupiska se nepřidávají
        self::assertSame([[100.0, 150.0], [100.0, 150.0]], PdfPages::sizes($labels[0]->labelPdf));
        self::assertSame(self::OrderNumber, $labels[0]->trackingNumber);
    }

    public function testOrderDocumentsAreAvailableSeparately(): void
    {
        $mock = new MockHttp([
            self::archive(),
            new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-1.4 prikaz'),
            new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-1.4 soupiska'),
        ]);

        $documents = self::handler($mock)->printOrderDocuments(self::OrderNumber);

        self::assertSame(['list' => '%PDF-1.4 prikaz', 'roll' => '%PDF-1.4 soupiska'], $documents);
        self::assertSame('https://zp.toptrans.cz/api/json/order/print-list/', (string) $mock->request(1)->getUri());
        self::assertSame('https://zp.toptrans.cz/api/json/order/print-roll/', (string) $mock->request(2)->getUri());
        self::assertSame(['id' => self::BatchId], self::body($mock, 2));
    }

    public function testSaveErrorIsReportedWithMessages(): void
    {
        $mock = new MockHttp([self::json(['status' => 'error', 'data' => [], 'errors' => ['0' => 'ADR musí mít zadanou hmotnost (KG) a počet kusů (COUNT)']])]);

        try {
            self::handler($mock)->createShipment(self::request());
            self::fail('Expected ShippingException');
        } catch (ShippingException $e) {
            self::assertSame('Toptrans order/save: ADR musí mít zadanou hmotnost (KG) a počet kusů (COUNT)', $e->getMessage());
        }
        self::assertSame(1, $mock->count());
    }

    public function testFailedSendDeletesUnsentOrder(): void
    {
        $mock = new MockHttp([
            self::ok(['id' => self::OrderId]),
            self::ok(['numOk' => 0, 'numErrors' => 1, 'message' => 'Počet odeslaných objednávek: 0']),
            self::ok(self::OrderId),
        ]);

        try {
            self::handler($mock)->createShipment(self::request());
            self::fail('Expected ShippingException');
        } catch (ShippingException $e) {
            self::assertStringContainsString('was not sent: Počet odeslaných objednávek: 0', $e->getMessage());
        }
        self::assertSame('https://zp.toptrans.cz/api/json/order/delete/', (string) $mock->request(2)->getUri());
        self::assertSame(['id' => self::OrderId], self::body($mock, 2));
    }

    public function testMissingOrderNumberInArchiveFailsWithBatchContext(): void
    {
        $mock = new MockHttp([self::ok(['id' => self::OrderId]), self::sent(), self::ok([])]);

        $this->expectException(ShippingException::class);
        $this->expectExceptionMessage('order 123456 was sent in batch 7059753, but its order number was not found');
        self::handler($mock)->createShipment(self::request());
    }

    public function testBadCredentialsAreHttpStatusException(): void
    {
        $mock = new MockHttp([new Response(401, [], 'Unauthorized')]);

        try {
            self::handler($mock)->createShipment(self::request());
            self::fail('Expected HttpStatusException');
        } catch (HttpStatusException $e) {
            self::assertSame(401, $e->getStatusCode());
        }
    }

    public function testUnsupportedCountryIsRejectedBeforeRequest(): void
    {
        $mock = new MockHttp();

        $this->expectException(\InvalidArgumentException::class);
        self::handler($mock)->createShipment(self::request(self::recipient('PL')));
    }

    public function testTrackingMapsLoadingAndDeliveredState(): void
    {
        $mock = new MockHttp([self::ok([
            [
                'orderNumber' => 17765017006, 'loadingDate' => '03.10.2017', 'itemFrom' => null, 'itemTo' => 6502033714,
                'label' => 'OBJ123', 'deliveryBranch' => '450 Mladá Boleslav', 'deliveryDriver' => 'Petr Blahovec (420)731073110',
                'deliveryDate' => '05.10.2017', 'status' => 'Doručeno',
            ],
        ])]);

        $tracking = self::handler($mock)->getParcelTracking('17765017006');

        self::assertSame('https://zp.toptrans.cz/api/json/order/search/', (string) $mock->request(0)->getUri());
        self::assertSame(['orderNumber' => '17765017006'], self::body($mock, 0));
        self::assertNotNull($tracking);
        self::assertSame([['loaded', false, false, null], ['Doručeno', true, false, 'Doručeno']], self::statuses($tracking));
        self::assertTrue($tracking->delivered);
        self::assertEquals(new \DateTimeImmutable('2017-10-05 00:00:00', new \DateTimeZone('Europe/Prague')), $tracking->deliveredDate);
        self::assertSame('17765017006', $tracking->parcelNumber);
        self::assertInstanceOf(ParcelTracking::class, $tracking);
        self::assertSame('OBJ123', $tracking->clientReference);
        self::assertSame('450 Mladá Boleslav', $tracking->parcelStatuses[1]->depotCity);
        self::assertSame('Petr Blahovec (420)731073110', $tracking->parcelStatuses[1]->statusInfo);
    }

    public function testTrackingInTransitAndUnknownOrder(): void
    {
        $mock = new MockHttp([
            self::ok([['orderNumber' => 17765016777, 'loadingDate' => '02.10.2017', 'deliveryDate' => null, 'status' => 'Na rozvozovém depu']]),
            self::ok([]),
        ]);
        $handler = self::handler($mock);

        $tracking = $handler->getParcelTracking('17765016777');
        self::assertNotNull($tracking);
        self::assertFalse($tracking->delivered);
        self::assertSame([['loaded', false, false, null], ['Na rozvozovém depu', false, false, null]], self::statuses($tracking));

        self::assertNull($handler->getParcelTracking('99999999999'));
        self::assertSame('https://www.toptrans.cz/preprava/cs/sledovani-zasilky?order_id=17765016777', $handler->getTrackingUrl('17765016777'));
    }

    public function testPrintProofOfDeliveryReturnsPdfOrError(): void
    {
        $mock = new MockHttp([
            new Response(200, ['Content-Type' => 'application/pdf'], self::Pdf),
            self::json(['status' => 'error', 'data' => [], 'errors' => ['Soubor neexistuje']]),
        ]);
        $handler = self::handler($mock);

        self::assertSame(self::Pdf, $handler->printProofOfDelivery('26601024577'));
        self::assertSame(['orderNumber' => '26601024577'], self::body($mock, 0));

        $this->expectExceptionMessage('Toptrans order/print-pod: Soubor neexistuje');
        $handler->printProofOfDelivery('1');
    }

    public function testInvalidCodBankAccountIsConfigError(): void
    {
        $this->expectException(\Ages\ShippingGateway\Common\ConfigException::class);
        new ToptransConfig('user', 'secret', codBankAccount: 'CZ65 0800 0000 1920 0014 5399');
    }
}
