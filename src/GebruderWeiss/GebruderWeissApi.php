<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\GebruderWeiss;

use Ages\ShippingGateway\Common\CarrierInterface;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\GebruderWeiss\Config\GebruderWeissConfig;
use Ages\ShippingGateway\GebruderWeiss\Tracking\GbwParcelStatus;
use Ages\ShippingGateway\GebruderWeiss\Tracking\GbwParcelTracking;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class GebruderWeissApi implements CarrierInterface
{
    public const string TrackUrl = 'https://www.gw-world.com/cz/';

    private ?string $token = null {
        get {
            if ($this->token !== null) {
                return $this->token;
            }
            return $this->token = $this->fetchToken('API_CUSAPI_TRANSPORT_ORDER_CREATE');
        }
    }

    private ?string $trackingToken = null {
        get {
            if ($this->trackingToken !== null) {
                return $this->trackingToken;
            }
            return $this->trackingToken = $this->fetchToken('API_CUSTNT_PACKAGES_STATUS_READ');
        }
    }

    private Client $httpClient;

    public function __construct(protected readonly GebruderWeissConfig $config)
    {
        $this->httpClient = new Client();
    }

    private function fetchToken(string $scope): string
    {
        try {
            $response = $this->httpClient->post($this->config->oauthUrl, [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->config->clientId,
                    'client_secret' => $this->config->clientSecret,
                    'scope' => $scope,
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

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
            $response = $this->httpClient->post($this->config->apiUrl . '/transport-order', [
                'json' => $payload,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
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

            $body = (string) $response->getBody();
            throw new ShippingException('GBW: Unexpected HTTP ' . $status . ': ' . $body);
        } catch (RequestException $e) {
            throw new ShippingException('GBW HTTP error: ' . $e->getMessage());
        }
    }

    public function getParcelTracking(string $consignmentId): ?ParcelTrackingInterface
    {
        try {
            $response = $this->httpClient->get(
                $this->config->trackingUrl . '/packages/' . urlencode($consignmentId) . '/status',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->trackingToken,
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

            $data = json_decode((string) $response->getBody(), true);

            if (!is_array($data)) {
                throw new ShippingException('GBW tracking: Invalid response');
            }

            return $this->parseTrackingResponse($consignmentId, $data);
        } catch (RequestException $e) {
            throw new ShippingException('GBW tracking error: ' . $e->getMessage());
        }
    }

    public function getTrackingUrl(string $consignmentId): string
    {
        return self::TrackUrl;
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
