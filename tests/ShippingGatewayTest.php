<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Tests;

use Ages\ShippingGateway\Common\Carrier;
use Ages\ShippingGateway\Common\ConfigException;
use Ages\ShippingGateway\Common\PickupAddress;
use Ages\ShippingGateway\Common\Shipment\ImportRequest;
use Ages\ShippingGateway\Common\Shipment\Parcel;
use Ages\ShippingGateway\Common\Shipment\RecipientAddress;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\Dpd\Config\DpdConfig;
use Ages\ShippingGateway\Dpd\Handler\DpdShipmentHandler;
use Ages\ShippingGateway\GebruderWeiss\Config\GebruderWeissConfig;
use Ages\ShippingGateway\Gls\Config\GlsConfig;
use Ages\ShippingGateway\PersonalCollection\Config\PersonalCollectionConfig;
use Ages\ShippingGateway\SupplierDelivery\Config\SupplierDeliveryConfig;
use Ages\ShippingGateway\ShippingGateway;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ShippingGatewayTest extends TestCase
{
    private static function pickup(): PickupAddress
    {
        return new PickupAddress('Wolfio', 'Hlavní', 'Praha', '11000', 'CZ', '+420777000000', 'info@example.com', '1');
    }

    public function testEveryCarrierIsRegisteredAndReportsMissingConfig(): void
    {
        $gateway = new ShippingGateway();

        foreach (Carrier::cases() as $carrier) {
            self::assertFalse($gateway->isConfigured($carrier));
            try {
                $gateway->trackingUrl($carrier, '1');
                self::fail('Expected ShippingException for ' . $carrier->name);
            } catch (ShippingException $e) {
                // registrovaný dopravce bez configu (neregistrovaný by hlásil „is not registered“)
                self::assertInstanceOf(ConfigException::class, $e->getPrevious());
                self::assertStringEndsWith('Config not configured', $e->getMessage(), $carrier->name);
            }
        }
    }

    public function testCarriersWithoutTrackingReturnNullAndEmptyUrl(): void
    {
        $gateway = new ShippingGateway(
            personalCollectionConfig: new PersonalCollectionConfig(self::pickup()),
            supplierDeliveryConfig: new SupplierDeliveryConfig(self::pickup()),
        );

        foreach ([Carrier::PersonalCollection, Carrier::SupplierDelivery] as $carrier) {
            self::assertTrue($gateway->isConfigured($carrier));
            self::assertNull($gateway->tracking($carrier, 'OBJ1'));
            self::assertSame('', $gateway->trackingUrl($carrier, 'OBJ1'));
            self::assertSame($carrier, $gateway->handler($carrier)->getCarrier());
        }
    }

    public function testHandlersAreLazyAndUseInjectedHttpClient(): void
    {
        $gateway = new ShippingGateway(dpdConfig: new DpdConfig('key', '1', self::pickup()));
        $mock = new MockHttp([new Response(404)]);
        $gateway->setHttpClient($mock->client);

        self::assertSame('https://tracking.dpd.de/status/cs_CZ/parcel/123', $gateway->trackingUrl(Carrier::Dpd, '123'));
        self::assertNull($gateway->tracking(Carrier::Dpd, '123'));
        self::assertSame(1, $mock->count());
        self::assertInstanceOf(DpdShipmentHandler::class, $gateway->dpd());
        self::assertSame($gateway->dpd(), $gateway->handler(Carrier::Dpd));

        // nový klient → nový handler
        $handler = $gateway->dpd();
        $gateway->setHttpClient(new MockHttp()->client);
        self::assertNotSame($handler, $gateway->dpd());
    }

    public function testErrorsAreWrappedInShippingException(): void
    {
        $gateway = new ShippingGateway(glsConfig: new GlsConfig('u', 'p', 1, self::pickup()));
        $gateway->setHttpClient(new MockHttp([new Response(200, [], 'not json')])->client);

        $this->expectException(ShippingException::class);
        $gateway->tracking(Carrier::Gls, '1');
    }

    public function testImportOnlyForCarrierWithImportHandler(): void
    {
        $gateway = new ShippingGateway(
            glsConfig: new GlsConfig('u', 'p', 1, self::pickup()),
            gwConfig: new GebruderWeissConfig('id', 'secret', 1, 'CZ1', self::pickup(), '0123456'),
        );
        $request = new ImportRequest(
            'IMP1',
            new RecipientAddress('Petr', 'Dodavatel', 'Skladová 1', 'Olomouc', '77900', 'CZ', '777000111', 'd@example.com'),
            [new Parcel(10)],
            new \DateTimeImmutable('-1 day'),
        );

        try {
            $gateway->createImport(Carrier::Gls, $request);
            self::fail('Expected ShippingException');
        } catch (ShippingException $e) {
            self::assertSame('Import is not supported for carrier Gls', $e->getMessage());
        }

        // GW import projde až na kontrolu data – bez HTTP požadavku
        $this->expectExceptionMessage('GBW import: collection date cannot be in the past');
        $gateway->createImport(Carrier::GebruderWeiss, $request);
    }

    public function testPplWidgetKey(): void
    {
        self::assertNull(new ShippingGateway()->pickupPointWidgetKey(Carrier::Gls));
        $this->expectException(ConfigException::class);
        new ShippingGateway()->pickupPointWidgetKey(Carrier::Ppl);
    }
}
