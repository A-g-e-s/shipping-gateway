<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\GebruderWeiss;

use Ages\ShippingGateway\Common\CarrierInterface;
use Ages\ShippingGateway\Common\Data;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\Common\ShipmentAlreadyExistsException;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\GebruderWeiss\Config\GebruderWeissConfig;
use Ages\ShippingGateway\GebruderWeiss\Tracking\GebruderWeissTrackingMapper;
use Ages\ShippingGateway\GebruderWeiss\Values\Method;
use Ages\ShippingGateway\GebruderWeiss\Values\TokenScope;
use Ages\ShippingGateway\Common\Http\AccessToken;
use Ages\ShippingGateway\Common\Http\HttpClient;
use Ages\ShippingGateway\Common\Http\HttpStatusException;
use Ages\ShippingGateway\Common\Http\TokenCache;
use GuzzleHttp\ClientInterface;

class GebruderWeissApi implements CarrierInterface
{
    public const string TrackUrl = 'https://my.gw-world.com/cs/trackntrace/-/search/';
    public const string TrackUrlFallback = 'https://my.gw-world.com/cs/trackntrace/';
    private const int HttpNotFound = 404;

    private const string CarrierName = 'GBW';

    private readonly HttpClient $http;
    /** Token pro každý TokenScope zvlášť (klíč = scope). */
    private readonly TokenCache $tokens;

    public function __construct(protected readonly GebruderWeissConfig $config, ?ClientInterface $httpClient = null)
    {
        // apiUrl a trackingUrl jsou různé hosty – cesty se skládají v jednotlivých metodách
        $this->http = new HttpClient(self::CarrierName, '', [], [], $httpClient);
        $this->tokens = new TokenCache(fn (string $scope): AccessToken => AccessToken::fromResponse(
            $this->http->sendJson('POST', $this->config->oauthUrl, [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->config->clientId,
                    'client_secret' => $this->config->clientSecret,
                    'scope' => $scope,
                ],
            ]),
            self::CarrierName,
        ));
    }

    /**
     * @param array<string, mixed> $payload
     * @throws ShipmentAlreadyExistsException objednávka se stejnou referencí už existuje (HTTP 409)
     * @throws ShippingException
     */
    public function createTransportOrder(array $payload): void
    {
        $url = $this->config->apiUrl . '/' . Method::TransportOrder->value;
        $response = $this->http->request('POST', $url, [
            'json' => $payload,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'accept-language' => 'cs',
            ],
        ], $this->tokens, TokenScope::TransportOrder->value);

        $status = $response->getStatusCode();

        if ($status === 202) {
            return;
        }
        if ($status === 409) {
            throw new ShipmentAlreadyExistsException('GBW: Conflict – order already exists');
        }

        throw $this->http->statusException('POST', $url, $response);
    }

    public function getParcelTracking(string $consignmentId, ?\DateTimeInterface $createdAt = null): ?ParcelTrackingInterface
    {
        $internalId = $this->resolveInternalOrderId($consignmentId, $createdAt);
        if ($internalId === null) {
            return null;
        }

        $data = $this->getTrackingJson(Method::OrderStatus->path(urlencode($internalId)), [
            'startIndex' => 1,
            'pageSize' => 20,
        ], TokenScope::OrdersStatus);

        return $data !== null ? GebruderWeissTrackingMapper::tracking($consignmentId, $data) : null;
    }

    private function resolveInternalOrderId(string $referenceNumber, ?\DateTimeInterface $createdAt): ?string
    {
        if ($createdAt !== null) {
            $base = \DateTimeImmutable::createFromInterface($createdAt);
            $dateFrom = $base->format('Y-m-d');
            $dateTo = $base->modify('+8 days')->format('Y-m-d');
        } else {
            $now = new \DateTimeImmutable();
            $dateFrom = $now->modify('-10 days')->format('Y-m-d');
            $dateTo = $now->format('Y-m-d');
        }

        $data = $this->getTrackingJson(Method::OrdersSearch->value, [
            'customerId'   => $this->config->customerId,
            'reference'    => $referenceNumber,
            'dateFrom'     => $dateFrom,
            'dateTo'       => $dateTo,
            'calculateETA' => 'false',
            'startIndex'   => 1,
            'pageSize'     => 10,
        ], TokenScope::OrdersStatus, 'cs-CZ');

        return $data !== null ? $this->extractOrderId($data) : null;
    }

    /**
     * GET na tracking API. Null při 404 (objednávka / balík neexistuje).
     *
     * @param array<string, mixed> $query
     * @return array<mixed>|null
     * @throws ShippingException
     */
    private function getTrackingJson(string $path, array $query, TokenScope $scope, string $language = 'cs'): ?array
    {
        try {
            return $this->http->sendJson('GET', $this->config->trackingUrl . '/' . $path, [
                'query' => $query,
                'headers' => [
                    'Accept' => 'application/json',
                    'accept-language' => $language,
                ],
            ], $this->tokens, $scope->value);
        } catch (HttpStatusException $e) {
            if ($e->getCode() === self::HttpNotFound) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * @param array<mixed> $data
     */
    private function extractOrderId(array $data): ?string
    {
        return Data::nonEmptyString($data, ['orderStatusList', 0, 'orderReferenced', 'orderId']);
    }

    public function getPackageTracking(string $barcode): ?ParcelTrackingInterface
    {
        $data = $this->getTrackingJson(Method::PackageStatus->path(urlencode($barcode)), [
            'startIndex' => 1,
            'pageSize'   => 20,
        ], TokenScope::PackagesStatus);

        return $data !== null ? GebruderWeissTrackingMapper::tracking($barcode, $data) : null;
    }

    public function getTrackingUrl(string $consignmentId): string
    {
        $city = trim($this->config->pickupAddress->city);
        if ($city === '') {
            return self::TrackUrlFallback;
        }

        return self::TrackUrl . rawurlencode($city) . '/' . rawurlencode($consignmentId);
    }
}
