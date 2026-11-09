<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Tests\Common\Http;

use Ages\ShippingGateway\Common\Http\AccessToken;
use Ages\ShippingGateway\Common\Http\HttpClient;
use Ages\ShippingGateway\Common\Http\HttpStatusException;
use Ages\ShippingGateway\Common\Http\TokenCache;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\Tests\MockHttp;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class HttpClientTest extends TestCase
{
    public function testResolvesRelativePathAgainstBaseUrlAndKeepsAbsoluteUrl(): void
    {
        $mock = new MockHttp([new Response(200, [], 'a'), new Response(200, [], 'b'), new Response(200, [], 'c')]);
        $http = new HttpClient('Test', 'https://api.example.com/v1/', [], [], $mock->client);

        self::assertSame('a', $http->send('GET', '/orders'));
        self::assertSame('b', $http->send('GET', 'orders/5'));
        self::assertSame('c', $http->send('GET', 'https://other.example.com/x'));

        self::assertSame('https://api.example.com/v1/orders', (string) $mock->request(0)->getUri());
        self::assertSame('https://api.example.com/v1/orders/5', (string) $mock->request(1)->getUri());
        self::assertSame('https://other.example.com/x', (string) $mock->request(2)->getUri());
    }

    public function testMergesDefaultHeadersWithRequestHeaders(): void
    {
        $mock = new MockHttp([new Response(200)]);
        $http = new HttpClient('Test', 'https://api.example.com', ['x-api-key' => 'secret', 'Accept' => 'application/json'], [], $mock->client);

        $http->send('GET', '/x', ['headers' => ['Accept' => 'application/pdf']]);

        self::assertSame('secret', $mock->request(0)->getHeaderLine('x-api-key'));
        self::assertSame('application/pdf', $mock->request(0)->getHeaderLine('Accept'));
    }

    public function testAppliesDefaultTimeoutsAndAlwaysDisablesHttpErrors(): void
    {
        $mock = new MockHttp([new Response(200)]);
        $http = new HttpClient('Test', '', [], [], $mock->client);

        $http->request('GET', 'https://api.example.com', ['http_errors' => true]);

        self::assertSame(HttpClient::DefaultTimeout, $mock->options(0)['timeout']);
        self::assertSame(HttpClient::DefaultConnectTimeout, $mock->options(0)['connect_timeout']);
        self::assertFalse($mock->options(0)['http_errors']);
    }

    public function testTimeoutCanBeOverriddenPerClientAndPerRequest(): void
    {
        $mock = new MockHttp([new Response(200), new Response(200)]);
        $http = new HttpClient('GLS', '', [], ['timeout' => 600.0, 'verify' => '/path/ca.pem'], $mock->client);

        $http->request('POST', 'https://api.example.com');
        $http->request('POST', 'https://api.example.com', ['timeout' => 5.0]);

        self::assertSame(600.0, $mock->options(0)['timeout']);
        self::assertSame('/path/ca.pem', $mock->options(0)['verify']);
        self::assertSame(5.0, $mock->options(1)['timeout']);
    }

    public function testErrorStatusThrowsHttpStatusExceptionWithCodeAndBodySample(): void
    {
        $body = '{"error":"not found"}' . str_repeat('x', 1000);
        $mock = new MockHttp([new Response(404, [], $body)]);
        $http = new HttpClient('PPL', 'https://api.example.com', [], [], $mock->client);

        try {
            $http->send('GET', '/label/5');
            self::fail('Expected HttpStatusException');
        } catch (HttpStatusException $e) {
            self::assertSame(404, $e->getCode());
            self::assertSame(404, $e->getStatusCode());
            self::assertSame($body, $e->getResponseBody());
            self::assertStringStartsWith('PPL GET /label/5 HTTP 404: {"error":"not found"}', $e->getMessage());
            self::assertLessThan(600, mb_strlen($e->getMessage()));
        }
    }

    public function testErrorFormatterBuildsMessage(): void
    {
        $mock = new MockHttp([new Response(400, [], '{"message":"bad"}')]);
        $http = new HttpClient('DPD', '', [], [], $mock->client, static fn (string $body): string => 'formatted ' . strlen($body));

        $this->expectException(HttpStatusException::class);
        $this->expectExceptionMessage('DPD POST https://api.example.com/x HTTP 400: formatted 17');
        $http->send('POST', 'https://api.example.com/x');
    }

    public function testEmptyErrorBodyFallsBackToReasonPhrase(): void
    {
        $mock = new MockHttp([new Response(503)]);
        $http = new HttpClient('PPL', 'https://api.example.com', [], [], $mock->client);

        $this->expectExceptionMessage('PPL GET x HTTP 503: Service Unavailable');
        $http->send('GET', 'x');
    }

    public function testRequestReturnsErrorResponseWithoutThrowing(): void
    {
        $mock = new MockHttp([new Response(409, [], 'conflict')]);
        $http = new HttpClient('GBW', '', [], [], $mock->client);

        $response = $http->request('POST', 'https://api.example.com/order');

        self::assertSame(409, $response->getStatusCode());
        $exception = $http->statusException('POST', 'order', $response);
        self::assertSame(409, $exception->getCode());
        self::assertSame('GBW POST order HTTP 409: conflict', $exception->getMessage());
    }

    public function testTransportErrorBecomesShippingExceptionWithCarrierMethodAndPath(): void
    {
        $mock = new MockHttp([new ConnectException('Connection timed out', new Request('GET', 'https://api.example.com/x'))]);
        $http = new HttpClient('GLS', 'https://api.example.com', [], [], $mock->client);

        try {
            $http->send('POST', 'PrintLabels');
            self::fail('Expected ShippingException');
        } catch (HttpStatusException) {
            self::fail('Transport error must not be HttpStatusException');
        } catch (ShippingException $e) {
            self::assertSame('GLS POST PrintLabels request error: Connection timed out', $e->getMessage());
            self::assertSame(0, $e->getCode());
            self::assertInstanceOf(ConnectException::class, $e->getPrevious());
        }
    }

    public function testSendJsonDecodesArrayAndAddsAcceptHeader(): void
    {
        $mock = new MockHttp([new Response(200, [], '{"a":1,"b":[2]}')]);
        $http = new HttpClient('Test', '', [], [], $mock->client);

        self::assertSame(['a' => 1, 'b' => [2]], $http->sendJson('GET', 'https://api.example.com'));
        self::assertSame('application/json', $mock->request(0)->getHeaderLine('Accept'));
    }

    public function testSendJsonRejectsInvalidJson(): void
    {
        $mock = new MockHttp([new Response(200, [], '<html>oops</html>'), new Response(200, [], '"string"')]);
        $http = new HttpClient('Test', '', [], [], $mock->client);

        try {
            $http->sendJson('GET', 'https://api.example.com/a');
            self::fail('Expected ShippingException');
        } catch (ShippingException $e) {
            self::assertStringContainsString('Test GET https://api.example.com/a: invalid JSON response', $e->getMessage());
            self::assertStringContainsString('<html>oops</html>', $e->getMessage());
        }

        $this->expectException(ShippingException::class);
        $http->sendJson('GET', 'https://api.example.com/b');
    }

    public function testBearerTokenIsSentAndReused(): void
    {
        $mock = new MockHttp([new Response(200), new Response(200)]);
        $counter = new \ArrayObject(['fetched' => 0]);
        $tokens = self::countingTokens($counter);
        $http = new HttpClient('Test', '', [], [], $mock->client);

        $http->send('GET', 'https://api.example.com/a', [], $tokens);
        $http->send('GET', 'https://api.example.com/b', ['headers' => ['X-Other' => '1']], $tokens);

        self::assertSame(1, $counter['fetched']);
        self::assertSame('Bearer token-1', $mock->request(0)->getHeaderLine('Authorization'));
        self::assertSame('Bearer token-1', $mock->request(1)->getHeaderLine('Authorization'));
        self::assertSame('1', $mock->request(1)->getHeaderLine('X-Other'));
    }

    public function testUnauthorizedResponseRefreshesTokenAndRetriesOnce(): void
    {
        $mock = new MockHttp([new Response(401), new Response(200, [], 'ok')]);
        $counter = new \ArrayObject(['fetched' => 0]);
        $tokens = self::countingTokens($counter);
        $http = new HttpClient('Test', '', [], [], $mock->client);

        self::assertSame('ok', $http->send('GET', 'https://api.example.com/a', [], $tokens));
        self::assertSame(2, $counter['fetched']);
        self::assertSame('Bearer token-1', $mock->request(0)->getHeaderLine('Authorization'));
        self::assertSame('Bearer token-2', $mock->request(1)->getHeaderLine('Authorization'));
    }

    public function testRepeatedUnauthorizedIsNotRetriedAgain(): void
    {
        $mock = new MockHttp([new Response(401), new Response(401, [], 'denied'), new Response(200)]);
        $counter = new \ArrayObject(['fetched' => 0]);
        $tokens = self::countingTokens($counter);
        $http = new HttpClient('Test', '', [], [], $mock->client);

        try {
            $http->send('GET', 'https://api.example.com/a', [], $tokens);
            self::fail('Expected HttpStatusException');
        } catch (HttpStatusException $e) {
            self::assertSame(401, $e->getCode());
            self::assertStringContainsString('denied', $e->getMessage());
        }
        self::assertSame(2, $mock->count());

        // neplatný token se zahodil – další požadavek získá nový
        $http->send('GET', 'https://api.example.com/b', [], $tokens);
        self::assertSame(3, $counter['fetched']);
    }

    public function testUnauthorizedWithoutBearerIsNotRetried(): void
    {
        $mock = new MockHttp([new Response(401), new Response(200)]);
        $http = new HttpClient('Test', '', [], [], $mock->client);

        try {
            $http->send('GET', 'https://api.example.com/a');
            self::fail('Expected HttpStatusException');
        } catch (HttpStatusException $e) {
            self::assertSame(401, $e->getCode());
        }
        self::assertSame(1, $mock->count());
    }

    /**
     * @param \ArrayObject<string, int> $counter
     */
    private static function countingTokens(\ArrayObject $counter): TokenCache
    {
        return new TokenCache(static function () use ($counter): AccessToken {
            $counter['fetched'] = $counter['fetched'] + 1;
            return new AccessToken('token-' . $counter['fetched'], 1800);
        });
    }
}
