<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Http;

use Ages\ShippingGateway\Common\ShippingException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * Společná HTTP vrstva dopravců nad Guzzle: základní URL, výchozí hlavičky a timeouty,
 * převod chyb na ShippingException a volitelná autorizace Bearer tokenem z TokenCache
 * (při 401 se token jednou obnoví a požadavek zopakuje).
 *
 * Guzzle klienta lze podstrčit (testy s MockHandler, sdílený klient v aplikaci).
 */
final class HttpClient
{
    public const float DefaultTimeout = 30.0;
    public const float DefaultConnectTimeout = 10.0;
    /** Kolik znaků těla odpovědi se vloží do chybové zprávy. */
    public const int BodySampleLength = 500;

    private const int HttpUnauthorized = 401;
    private const int HttpErrorFrom = 400;

    private readonly ClientInterface $client;

    /** @var array<string, mixed> */
    private readonly array $options;

    /** @var (\Closure(string): string)|null */
    private readonly ?\Closure $errorFormatter;

    /**
     * @param string                          $carrier        název dopravce do chybových zpráv
     * @param string                          $baseUrl        základ pro relativní cesty (absolutní URL se použije beze změny)
     * @param array<string, string>           $defaultHeaders hlavičky každého požadavku (požadavek je může přepsat)
     * @param array<string, mixed>            $options        výchozí Guzzle volby, např. timeout, connect_timeout, verify
     * @param (\Closure(string): string)|null $errorFormatter tělo chybové odpovědi → text do zprávy (výchozí: začátek těla)
     */
    public function __construct(
        private readonly string $carrier,
        private readonly string $baseUrl = '',
        private readonly array $defaultHeaders = [],
        array $options = [],
        ?ClientInterface $client = null,
        ?\Closure $errorFormatter = null,
    ) {
        $this->client = $client ?? new Client();
        $this->options = array_replace([
            'timeout' => self::DefaultTimeout,
            'connect_timeout' => self::DefaultConnectTimeout,
        ], $options);
        $this->errorFormatter = $errorFormatter;
    }

    public function getCarrier(): string
    {
        return $this->carrier;
    }

    /**
     * Odešle požadavek a vrátí odpověď bez ohledu na HTTP kód (kromě opakování po 401 s tokenem).
     *
     * @param array<string, mixed> $options Guzzle volby požadavku
     * @param TokenCache|null      $bearer  přidá hlavičku Authorization: Bearer
     * @throws ShippingException chyba spojení / timeout
     */
    public function request(string $method, string $uri, array $options = [], ?TokenCache $bearer = null, string $tokenKey = TokenCache::DefaultKey): ResponseInterface
    {
        if ($bearer === null) {
            return $this->dispatch($method, $uri, $options);
        }

        $response = $this->dispatch($method, $uri, self::withBearer($options, $bearer->get($tokenKey)));
        if ($response->getStatusCode() !== self::HttpUnauthorized) {
            return $response;
        }

        // token mohl vypršet nebo být zneplatněn dřív, než jsme čekali – jednou zkusit nový
        $bearer->invalidate($tokenKey);
        $response = $this->dispatch($method, $uri, self::withBearer($options, $bearer->get($tokenKey)));
        if ($response->getStatusCode() === self::HttpUnauthorized) {
            $bearer->invalidate($tokenKey);
        }

        return $response;
    }

    /**
     * Odešle požadavek a vrátí tělo odpovědi.
     *
     * @param array<string, mixed> $options
     * @throws HttpStatusException HTTP ≥ 400 (kód v getCode())
     * @throws ShippingException   chyba spojení / timeout
     */
    public function send(string $method, string $uri, array $options = [], ?TokenCache $bearer = null, string $tokenKey = TokenCache::DefaultKey): string
    {
        $response = $this->request($method, $uri, $options, $bearer, $tokenKey);
        if ($response->getStatusCode() >= self::HttpErrorFrom) {
            throw $this->statusException($method, $uri, $response);
        }

        return (string) $response->getBody();
    }

    /**
     * Odešle požadavek a vrátí odpověď dekódovanou z JSON jako pole.
     *
     * @param array<string, mixed> $options
     * @return array<mixed>
     * @throws HttpStatusException HTTP ≥ 400
     * @throws ShippingException   chyba spojení, neplatný JSON
     */
    public function sendJson(string $method, string $uri, array $options = [], ?TokenCache $bearer = null, string $tokenKey = TokenCache::DefaultKey): array
    {
        $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        if (!isset($headers['Accept']) && !isset($this->defaultHeaders['Accept'])) {
            $options['headers'] = $headers + ['Accept' => 'application/json'];
        }

        return $this->decodeJson($this->send($method, $uri, $options, $bearer, $tokenKey), $method, $uri);
    }

    /**
     * @return array<mixed>
     * @throws ShippingException tělo není JSON objekt / pole
     */
    public function decodeJson(string $body, string $method, string $uri): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new ShippingException(sprintf(
                '%s %s %s: invalid JSON response (%s): %s',
                $this->carrier,
                $method,
                $uri,
                json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : 'not an object',
                self::sample($body),
            ));
        }

        return $data;
    }

    /**
     * Výjimka pro neúspěšnou odpověď – pro dopravce, kteří kontrolují HTTP kód sami.
     */
    public function statusException(string $method, string $uri, ResponseInterface $response): HttpStatusException
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $detail = $this->errorFormatter !== null ? ($this->errorFormatter)($body) : self::sample($body);

        return new HttpStatusException(
            sprintf('%s %s %s HTTP %d: %s', $this->carrier, $method, $uri, $status, $detail !== '' ? $detail : $response->getReasonPhrase()),
            $status,
            $body,
        );
    }

    public static function sample(string $body): string
    {
        return mb_substr(trim($body), 0, self::BodySampleLength);
    }

    public function resolveUrl(string $uri): string
    {
        if ($this->baseUrl === '' || preg_match('~^https?://~i', $uri) === 1) {
            return $uri;
        }
        if ($uri === '') {
            return $this->baseUrl;
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($uri, '/');
    }

    /**
     * @param array<string, mixed> $options
     * @throws ShippingException
     */
    private function dispatch(string $method, string $uri, array $options): ResponseInterface
    {
        $headers = $this->defaultHeaders;
        if (is_array($options['headers'] ?? null)) {
            $headers = array_replace($headers, $options['headers']);
        }

        $options = array_replace($this->options, $options, [
            'headers' => $headers,
            // HTTP kódy vyhodnocujeme sami, Guzzle na ně nemá házet výjimky
            'http_errors' => false,
        ]);

        try {
            return $this->client->request($method, $this->resolveUrl($uri), $options);
        } catch (GuzzleException $e) {
            throw new ShippingException(sprintf('%s %s %s request error: %s', $this->carrier, $method, $uri, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function withBearer(array $options, string $token): array
    {
        $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $headers['Authorization'] = 'Bearer ' . $token;
        $options['headers'] = $headers;

        return $options;
    }
}
