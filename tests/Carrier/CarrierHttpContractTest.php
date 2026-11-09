<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Tests\Carrier;

use Ages\ShippingGateway\Common\Http\HttpStatusException;
use Ages\ShippingGateway\Common\PickupAddress;
use Ages\ShippingGateway\Common\ShipmentAlreadyExistsException;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\CzechPost\Config\CzechPostConfig;
use Ages\ShippingGateway\CzechPost\CzechPostApi;
use Ages\ShippingGateway\CzechPost\CzechPostException;
use Ages\ShippingGateway\Dpd\Config\DpdConfig;
use Ages\ShippingGateway\Dpd\DpdApi;
use Ages\ShippingGateway\GebruderWeiss\Config\GebruderWeissConfig;
use Ages\ShippingGateway\GebruderWeiss\GebruderWeissApi;
use Ages\ShippingGateway\Gls\Config\GlsConfig;
use Ages\ShippingGateway\Gls\GlsApi;
use Ages\ShippingGateway\OneByAllegro\Config\OneByAllegroConfig;
use Ages\ShippingGateway\OneByAllegro\OneByAllegroApi;
use Ages\ShippingGateway\Ppl\Config\PplConfig;
use Ages\ShippingGateway\Ppl\PplApi;
use Ages\ShippingGateway\Tests\MockHttp;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Chování dopravců na úrovni HTTP, na které spoléhají handlery (kódy chyb, tokeny).
 * Obsah požadavků jednotlivých dopravců se tu netestuje.
 */
final class CarrierHttpContractTest extends TestCase
{
    private static function pickup(): PickupAddress
    {
        return new PickupAddress('Wolfio', 'Hlavní', 'Praha', '11000', 'CZ', '+420777000000', 'info@example.com', '1');
    }

    private static function json(mixed $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    public function testPplReusesTokenAndRefreshesItAfterUnauthorized(): void
    {
        $mock = new MockHttp([
            self::json(['access_token' => 'first', 'expires_in' => 1800]),
            new Response(200, [], 'label-1'),
            new Response(401),
            self::json(['access_token' => 'second', 'expires_in' => 1800]),
            new Response(200, [], 'label-2'),
        ]);
        $api = new PplApi(new PplConfig('id', 'secret', self::pickup()), $mock->client);

        self::assertSame('label-1', $api->getLabel('label/1'));
        self::assertSame('label-2', $api->getLabel('https://api.dhl.com/ecs/ppl/myapi2/label/2'));

        self::assertSame('https://api.dhl.com/ecs/ppl/myapi2/login/getAccessToken', (string) $mock->request(0)->getUri());
        self::assertSame('https://api.dhl.com/ecs/ppl/myapi2/label/1', (string) $mock->request(1)->getUri());
        self::assertSame('Bearer first', $mock->request(1)->getHeaderLine('Authorization'));
        self::assertSame('Bearer first', $mock->request(2)->getHeaderLine('Authorization'));
        self::assertSame('Bearer second', $mock->request(4)->getHeaderLine('Authorization'));
    }

    public function testPplLabelNotReadyHasCode404(): void
    {
        $mock = new MockHttp([
            self::json(['access_token' => 'token', 'expires_in' => 1800]),
            new Response(404, [], 'Not found'),
        ]);
        $api = new PplApi(new PplConfig('id', 'secret', self::pickup()), $mock->client);

        try {
            $api->getLabel('label/1');
            self::fail('Expected ShippingException');
        } catch (ShippingException $e) {
            // PplShipmentHandler::waitForLabel opakuje jen při 404
            self::assertSame(404, $e->getCode());
        }
    }

    public function testPplCreateBatchReturnsIdFromLocationAndFormatsValidationErrors(): void
    {
        $mock = new MockHttp([
            self::json(['access_token' => 'token', 'expires_in' => 1800]),
            new Response(201, ['Location' => 'https://api.dhl.com/ecs/ppl/myapi2/shipment/batch/abc-123']),
            self::json(['title' => 'Bad Request', 'errors' => ['recipient.zipCode' => ['Invalid zip']]], 400),
        ]);
        $api = new PplApi(new PplConfig('id', 'secret', self::pickup()), $mock->client);
        $parcel = $this->createStub(\Ages\ShippingGateway\Ppl\Entity\ParcelEntity::class);

        self::assertSame('abc-123', $api->createBatch($parcel));

        try {
            $api->createBatch($parcel);
            self::fail('Expected HttpStatusException');
        } catch (HttpStatusException $e) {
            self::assertSame(400, $e->getCode());
            self::assertStringContainsString('Bad Request<br />&nbsp;&nbsp;&nbsp;&nbsp;-> recipient.zipCode (Invalid zip)', $e->getMessage());
        }
    }

    public function testGebruderWeissConflictMapsToShipmentAlreadyExists(): void
    {
        $mock = new MockHttp([
            self::json(['access_token' => 'token', 'expires_in' => 300]),
            new Response(409, [], '{"message":"exists"}'),
        ]);
        $api = new GebruderWeissApi(self::gwConfig(), $mock->client);

        $this->expectException(ShipmentAlreadyExistsException::class);
        $api->createTransportOrder(['orderId' => 'X']);
    }

    public function testGebruderWeissUsesTokenPerScopeAndReturnsNullForUnknownPackage(): void
    {
        $mock = new MockHttp([
            self::json(['access_token' => 'order-token', 'expires_in' => 300]),
            new Response(202),
            self::json(['access_token' => 'package-token', 'expires_in' => 300]),
            new Response(404),
        ]);
        $api = new GebruderWeissApi(self::gwConfig(), $mock->client);

        $api->createTransportOrder(['orderId' => 'X']);
        self::assertNull($api->getPackageTracking('123'));

        self::assertStringContainsString('scope=API_CUSAPI_TRANSPORT_ORDER_CREATE', (string) $mock->request(0)->getBody());
        self::assertStringContainsString('scope=API_CUSTNT_PACKAGES_STATUS_READ', (string) $mock->request(2)->getBody());
        self::assertSame('Bearer package-token', $mock->request(3)->getHeaderLine('Authorization'));
    }

    public function testDpdTrackingReturnsNullForUnknownParcelAndSendsApiKey(): void
    {
        $mock = new MockHttp([new Response(404, [], '{"message":"Not found"}')]);
        $api = new DpdApi(new DpdConfig('api-key', '123', self::pickup()), $mock->client);

        self::assertNull($api->getTracking('12345678901234'));
        self::assertSame('api-key', $mock->request(0)->getHeaderLine('x-api-key'));
        self::assertSame('https://geoapi.dpd.cz/v2/parcels/12345678901234/tracking', (string) $mock->request(0)->getUri());
        self::assertSame(60, $mock->options(0)['timeout']);
    }

    public function testOneByAllegroRetriesWithNewTokenAndKeepsStatusCode(): void
    {
        $mock = new MockHttp([
            self::json(['accessToken' => 'first', 'expiresIn' => 3600]),
            new Response(401),
            self::json(['accessToken' => 'second', 'expiresIn' => 3600]),
            self::json(['shipmentId' => 'S1', 'packages' => []]),
            self::json(['errors' => [['path' => 'shipmentIds', 'userMessage' => 'Not ready']]], 404),
        ]);
        $api = new OneByAllegroApi(new OneByAllegroConfig('user', 'pass', self::pickup()), $mock->client);

        self::assertSame(['shipmentId' => 'S1', 'packages' => []], $api->getShipment('S1'));
        self::assertSame('Bearer second', $mock->request(3)->getHeaderLine('Authorization'));

        try {
            $api->getLabels(['S1']);
            self::fail('Expected HttpStatusException');
        } catch (HttpStatusException $e) {
            self::assertSame(404, $e->getCode());
            self::assertStringContainsString('shipmentIds: Not ready', $e->getMessage());
        }
    }

    public function testGlsUsesLongerTimeoutThatCanBeOverridden(): void
    {
        $mock = new MockHttp([self::json(['ParcelNumber' => '']), self::json(['ParcelNumber' => ''])]);

        new GlsApi(new GlsConfig('user', 'pass', 1, self::pickup()), $mock->client)->getParcelTracking('1');
        new GlsApi(new GlsConfig('user', 'pass', 1, self::pickup(), timeout: 600.0), $mock->client)->getParcelTracking('1');

        self::assertSame(GlsApi::DefaultTimeoutSeconds, $mock->options(0)['timeout']);
        self::assertSame(600.0, $mock->options(1)['timeout']);
        self::assertSame('https://api.mygls.cz/ParcelService.svc/json/GetParcelStatuses', (string) $mock->request(0)->getUri());
    }

    public function testCzechPostKeepsCertificateAndExceptionWithExtraData(): void
    {
        $mock = new MockHttp([new Response(400, [], '{"error":"Invalid signature"}')]);
        $api = new class (self::czechPostConfig(), $mock->client) extends CzechPostApi {
            /** @return mixed */
            public function tracking(string $id): mixed
            {
                return $this->parcelStatusesApi($id);
            }
        };

        try {
            $api->tracking('DR123');
            self::fail('Expected CzechPostException');
        } catch (CzechPostException $e) {
            self::assertInstanceOf(ShippingException::class, $e);
            self::assertSame(400, $e->getCode());
            self::assertSame(['Invalid signature'], $e->getExtra());
        }
        self::assertSame('/path/to/cert.pem', $mock->options(0)['verify']);
        self::assertSame(15.0, $mock->options(0)['timeout']);
        self::assertStringStartsWith('CP-HMAC-SHA256 nonce="', $mock->request(0)->getHeaderLine('Authorization'));
    }

    private static function gwConfig(): GebruderWeissConfig
    {
        return new GebruderWeissConfig('id', 'secret', 1, 'CZ1', self::pickup(), '0123456');
    }

    private static function czechPostConfig(): CzechPostConfig
    {
        return new CzechPostConfig('token', 'secret', 1, 'customer', '11000', 1, '/path/to/cert.pem');
    }
}
