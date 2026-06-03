<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\GebruderWeiss;

use Ages\ShippingGateway\Common\CarrierInterface;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\GebruderWeiss\Config\GebruderWeissConfig;
use Ages\ShippingGateway\GebruderWeiss\Tracking\GbwParcelStatus;
use Ages\ShippingGateway\GebruderWeiss\Tracking\GbwParcelTracking;
use Ages\ShippingGateway\GebruderWeiss\Values\Method;
use Ages\ShippingGateway\GebruderWeiss\Values\TokenScope;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class GebruderWeissApi implements CarrierInterface
{
    public const string TrackUrl = 'https://my.gw-world.com/cz/trackntrace/-/search/';
    public const string TrackUrlFallback = 'https://my.gw-world.com/cz/trackntrace/';

    /** @var array<string, string> */
    private array $tokens = [];

    private Client $httpClient;

    public function __construct(protected readonly GebruderWeissConfig $config)
    {
        $this->httpClient = new Client();
    }

    private function getToken(TokenScope $scope): string
    {
        return $this->tokens[$scope->value] ??= $this->fetchToken($scope);
    }

    private function fetchToken(TokenScope $scope): string
    {
        try {
            $response = $this->httpClient->post($this->config->oauthUrl, [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->config->clientId,
                    'client_secret' => $this->config->clientSecret,
                    'scope' => $scope->value,
                ],
            ]);

            $data = json_decode((string)$response->getBody(), true);

            if (!is_array($data) || !isset($data['access_token']) || !is_string($data['access_token'])) {
                throw new ShippingException('GBW: Token was not created');
            }

            return $data['access_token'];
        } catch (RequestException $e) {
            throw new ShippingException('GBW auth error: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @throws ShippingException
     */
    public function createTransportOrder(array $payload): void
    {
        try {
            $response = $this->httpClient->post($this->config->apiUrl . '/' . Method::TransportOrder->value, [
                'json' => $payload,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getToken(TokenScope::TransportOrder),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'accept-language' => 'cs',
                ],
                'http_errors' => false,
            ]);

            $status = $response->getStatusCode();

            if ($status === 202) {
                return;
            }
            if ($status === 401) {
                throw new ShippingException('GBW: Unauthorized');
            }
            if ($status === 409) {
                throw new ShippingException('GBW: Conflict – order already exists');
            }

            $body = (string)$response->getBody();
            throw new ShippingException('GBW: Unexpected HTTP ' . $status . ': ' . $body);
        } catch (RequestException $e) {
            throw new ShippingException('GBW HTTP error: ' . $e->getMessage());
        }
    }

    public function getParcelTracking(string $consignmentId, ?\DateTimeInterface $createdAt = null): ?ParcelTrackingInterface
    {
        $internalId = $this->resolveInternalOrderId($consignmentId, $createdAt);
        if ($internalId === null) {
            return null;
        }

        try {
            $response = $this->httpClient->get(
                $this->config->trackingUrl . '/' . Method::OrderStatus->path(urlencode($internalId)),
                [
                    'query' => [
                        'startIndex' => 1,
                        'pageSize' => 20,
                    ],
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->getToken(TokenScope::OrdersStatus),
                        'Accept' => 'application/json',
                        'accept-language' => 'cs',
                    ],
                    'http_errors' => false,
                ]
            );

            $status = $response->getStatusCode();

            if ($status === 404) {
                return null;
            }

            if ($status !== 200) {
                throw new ShippingException('GBW tracking: HTTP ' . $status);
            }

            $data = json_decode((string)$response->getBody(), true);

            if (!is_array($data)) {
                throw new ShippingException('GBW tracking: Invalid response');
            }

            return $this->parseTrackingResponse($consignmentId, $data);
        } catch (RequestException $e) {
            throw new ShippingException('GBW tracking error: ' . $e->getMessage());
        }
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

        try {
            $response = $this->httpClient->get(
                $this->config->trackingUrl . '/' . Method::OrdersSearch->value,
                [
                    'query' => [
                        'customerId'   => $this->config->customerId,
                        'reference'    => $referenceNumber,
                        'dateFrom'     => $dateFrom,
                        'dateTo'       => $dateTo,
                        'calculateETA' => 'false',
                        'startIndex'   => 1,
                        'pageSize'     => 10,
                    ],
                    'headers' => [
                        'Authorization'   => 'Bearer ' . $this->getToken(TokenScope::OrdersStatus),
                        'Accept'          => 'application/json',
                        'accept-language' => 'cs-CZ',
                    ],
                    'http_errors' => false,
                ]
            );

            $status = $response->getStatusCode();

            if ($status === 404) {
                return null;
            }

            if ($status !== 200) {
                throw new ShippingException('GBW tracking search: HTTP ' . $status);
            }

            $data = json_decode((string) $response->getBody(), true);

            if (!is_array($data)) {
                throw new ShippingException('GBW tracking search: Invalid response');
            }

            return $this->extractOrderId($data);
        } catch (RequestException $e) {
            throw new ShippingException('GBW tracking error: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractOrderId(array $data): ?string
    {
        $list = $data['orderStatusList'] ?? null;
        if (!is_array($list) || !isset($list[0])) {
            return null;
        }

        $refs = $list[0]['orderReferenced']['references'] ?? null;
        if (is_array($refs) && isset($refs[0]['orderId']) && is_string($refs[0]['orderId']) && $refs[0]['orderId'] !== '') {
            return $refs[0]['orderId'];
        }

        return null;
    }

    public function getPackageTracking(string $barcode): ?ParcelTrackingInterface
    {
        try {
            $response = $this->httpClient->get(
                $this->config->trackingUrl . '/' . Method::PackageStatus->path(urlencode($barcode)),
                [
                    'query'       => [
                        'startIndex' => 1,
                        'pageSize'   => 20,
                    ],
                    'headers'     => [
                        'Authorization'   => 'Bearer ' . $this->getToken(TokenScope::PackagesStatus),
                        'Accept'          => 'application/json',
                        'accept-language' => 'cs',
                    ],
                    'http_errors' => false,
                ]
            );

            $status = $response->getStatusCode();

            if ($status === 404) {
                return null;
            }

            if ($status !== 200) {
                throw new ShippingException('GBW package tracking: HTTP ' . $status);
            }

            $data = json_decode((string) $response->getBody(), true);

            if (!is_array($data)) {
                throw new ShippingException('GBW package tracking: Invalid response');
            }

            return $this->parseTrackingResponse($barcode, $data);
        } catch (RequestException $e) {
            throw new ShippingException('GBW package tracking error: ' . $e->getMessage());
        }
    }

    public function getTrackingUrl(string $consignmentId): string
    {
        $company = trim((string)$this->config->pickupAddress->company);
        if ($company === '') {
            return self::TrackUrlFallback;
        }

        return self::TrackUrl . rawurlencode($company) . '/' . rawurlencode($consignmentId);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseTrackingResponse(string $barcode, array $data): GbwParcelTracking
    {
        $tracking = GbwParcelTracking::of($barcode);

        $statusHistory = $data['statusHistory'] ?? null;
        if (is_array($statusHistory)) {
            $this->appendTrackingEvents($tracking, $statusHistory);
        }

        if ($tracking->parcelStatuses === []) {
            $statusCurrent = $data['statusCurrent'] ?? null;
            if (is_array($statusCurrent)) {
                $tracking->addStatus(GbwParcelStatus::of($statusCurrent));
            }
        }

        if ($tracking->parcelStatuses === []) {
            $this->appendTrackingEvents($tracking, $data);
        }

        return $tracking;
    }

    /**
     * @param array<int|string, mixed> $events
     */
    private function appendTrackingEvents(GbwParcelTracking $tracking, array $events): void
    {
        foreach ($events as $event) {
            if (is_array($event) && isset($event['eventCode'], $event['eventDateTime'])) {
                $tracking->addStatus(GbwParcelStatus::of($event));
            }
        }
    }
}
