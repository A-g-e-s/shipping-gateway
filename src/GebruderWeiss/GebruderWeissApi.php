<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\GebruderWeiss;

use Ages\ShippingGateway\Common\CarrierInterface;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\GebruderWeiss\Config\GebruderWeissConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class GebruderWeissApi implements CarrierInterface
{
    private ?string $token = null {
        get {
            if ($this->token !== null) {
                return $this->token;
            }

            try {
                $response = $this->httpClient->post($this->config->oauthUrl, [
                    'form_params' => [
                        'grant_type' => 'client_credentials',
                        'client_id' => $this->config->clientId,
                        'client_secret' => $this->config->clientSecret,
                        'scope' => 'API_CUSAPI_TRANSPORT_ORDER_CREATE',
                    ],
                ]);

                $data = json_decode((string)$response->getBody(), true);

                if (!is_array($data) || !isset($data['access_token']) || !is_string($data['access_token'])) {
                    throw new ShippingException('GBW: Token was not created');
                }

                return $this->token = $data['access_token'];
            } catch (RequestException $e) {
                throw new ShippingException('GBW auth error: ' . $e->getMessage());
            }
        }
    }
    private Client $httpClient;

    public function __construct(protected readonly GebruderWeissConfig $config)
    {
        $this->httpClient = new Client();
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
                    'accept-language' => 'en-US',
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
        return null;
    }
}
