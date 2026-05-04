<?php

namespace Ages\ShippingGateway\Ppl;

use Ages\ShippingGateway\Common\CarrierInterface;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\Ppl\Config\PplConfig;
use Ages\ShippingGateway\Ppl\Entity\AddressEntity;
use Ages\ShippingGateway\Ppl\Entity\ParcelEntity;
use Ages\ShippingGateway\Ppl\Entity\ParcelStatus;
use Ages\ShippingGateway\Ppl\Entity\ParcelTracking;
use Ages\ShippingGateway\Ppl\Values\Method;
use Ages\ShippingGateway\Ppl\Values\ShipmentState;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;

class PplApi implements CarrierInterface
{
    private ?string $token = null {
        get {
            if ($this->token !== null) {
                return $this->token;
            }

            $data = [
                'client_id' => $this->config->clientId,
                'client_secret' => $this->config->clientSecret,
                'grant_type' => $this->config->grantType,
                'scope' => $this->config->scope,
            ];

            try {
                $response = $this->httpClient->post($this->config->url . Method::AccessToken->value, [
                    'form_params' => $data,
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded'
                    ]
                ]);

                if ($response->getStatusCode() === 401) {
                    throw new ShippingException('PPL: Unauthorized');
                }

                $values = json_decode($response->getBody(), true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new ShippingException('PPL: Response JSON Error');
                }

                if (is_array($values) && isset($values['access_token']) && is_string($values['access_token'])) {
                    return $this->token = $values['access_token'];
                }

                throw new ShippingException('PPL: Token was not created');
            } catch (RequestException $e) {
                throw new ShippingException('PPL Guzzle Error: ' . $e->getMessage());
            }
        }
    }
    private Client $httpClient;

    public function __construct(protected readonly PplConfig $config)
    {
        $this->httpClient = new Client();
    }

    protected function getPickupAddress(): AddressEntity
    {
        $p = $this->config->pickupAddress;
        return AddressEntity::of(
            $p->name,
            $p->getStreetWithNumber(),
            $p->city,
            $p->zip,
            $p->country,
            null,
            '',
            $p->phone,
            $p->email,
        );
    }

    public function createBatch(ParcelEntity $parcelEntity): string
    {
        try {
            $response = $this->httpClient->post($this->config->url . Method::ShipmentBatch->value, [
                'json' => $parcelEntity->toArray(),
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ]
            ]);

            $httpCode = $response->getStatusCode();

            if ($httpCode === 201) {
                $location = $response->getHeader('Location')[0] ?? null;
                if (is_string($location)) {
                    return basename($location);
                }
            }

            if ($httpCode === 400) {
                $body = (string)$response->getBody();
                $data = json_decode($body, true);
                $error = 'Bad Request';
                if (is_array($data) && isset($data['errors']) && is_array($data['errors'])) {
                    $messages = [];
                    foreach ($data['errors'] as $field => $fieldMessages) {
                        if (!is_array($fieldMessages)) {
                            continue;
                        }
                        foreach ($fieldMessages as $msg) {
                            if (!is_scalar($msg)) {
                                continue;
                            }
                            $messages[] = sprintf('&nbsp;&nbsp;&nbsp;&nbsp;-> %s (%s)', $field, $msg);
                        }
                    }
                    if ($messages !== []) {
                        $error .= '<br />' . implode('<br />', $messages);
                    }
                }

                throw new ShippingException($error);
            }

            if ($httpCode === 401) {
                throw new ShippingException('PPL: Unauthorized access');
            }
            if ($httpCode === 500) {
                throw new ShippingException('PPL: Server Error');
            }
            if ($httpCode === 503) {
                throw new ShippingException('PPL: Service Unavailable');
            }
            throw new ShippingException('PPL: Incorrect response HTTP Code: ' . $httpCode);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $responseBody = '';
            $statusCode = '?';

            if ($response !== null) {
                $responseBody = (string) $response->getBody();
                $statusCode = (string) $response->getStatusCode();
            }

            throw new ShippingException( 'PPL createBatch HTTP ' . $statusCode . ': ' . $e->getMessage() . ' | body: ' . $responseBody );
        }
    }

    public function getLabel(string $url): string
    {
        if (!preg_match('~^https?://~i', $url)) {
            $url = rtrim($this->config->url, '/') . '/' . ltrim($url, '/');
        }

        try {
            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type' => 'text/plain'
                ]
            ]);
            return $response->getBody()->getContents();
        } catch (ClientException $exception) {
            $body = (string)$exception->getResponse()->getBody();
            throw new ShippingException('PPL getLabel HTTP ' . $exception->getResponse()->getStatusCode() . ': ' . $body);
        } catch (RequestException $exception) {
            throw new ShippingException('PPL getLabel request error: ' . $exception->getMessage(), 0, $exception);
        }
    }

    public function getBatchLabel(string $batchId): string
    {
        $url = rtrim($this->config->url, '/') . '/' . Method::ShipmentBatch->value . '/' . $batchId . '/' . Method::ShipmentBatchLabel->value;

        try {
            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type' => 'text/plain'
                ]
            ]);
            return $response->getBody()->getContents();
        } catch (ClientException $exception) {
            $body = (string) $exception->getResponse()->getBody();
            throw new ShippingException('PPL getBatchLabel HTTP ' . $exception->getResponse()->getStatusCode() . ': ' . $body, $exception->getResponse()->getStatusCode(), $exception);
        } catch (RequestException $exception) {
            throw new ShippingException('PPL getBatchLabel request error: ' . $exception->getMessage(), 0, $exception);
        }
    }

    public function getStatus(string $batchId): ?\stdClass
    {
        try {
            $method = $this->config->url . Method::ShipmentBatch->value . '/' . $batchId;
            $response = $this->httpClient->get($method, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type' => 'text/plain'
                ]
            ]);
            $response = json_decode($response->getBody()->getContents());

            if (!$response instanceof \stdClass || !isset($response->items) || !is_array($response->items) || !isset($response->items[0]) || !$response->items[0] instanceof \stdClass) {
                throw new ShippingException('PPL: invalid batch status response');
            }

            $item = $response->items[0];
            $importState = $item->importState ?? null;

            if ($importState === 'Complete') {
                return $response;
            }

            if ($importState === 'Error') {
                throw new ShippingException('PPL: batch processing failed for batch ' . $batchId);
            }

            return null;
        } catch (ClientException $exception) {
            $body = (string)$exception->getResponse()->getBody();
            throw new ShippingException('PPL getStatus HTTP ' . $exception->getResponse()->getStatusCode() . ': ' . $body);
        } catch (RequestException $exception) {
            throw new ShippingException('PPL getStatus request error: ' . $exception->getMessage(), 0, $exception);
        }
    }

    public function getTracking(string $consignmentId): ?\stdClass
    {
        try {
            $data = [
                'ShipmentNumbers' => [$consignmentId],
                'Limit' => 10,
                'Offset' => 0
            ];
            $method = $this->config->url . Method::Shipment->value . '?' . http_build_query($data);
            $response = $this->httpClient->get($method, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type' => 'text/plain',
                    'Accept-Language' => 'cs-CZ',
                ]
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $body = $response->getBody();
            $json = json_decode($body->getContents());
            $body->close();
            if (is_array($json) && $json[0] instanceof \stdClass) {
                return $json[0];
            }
            return null;
        } catch (ClientException $exception) {
            throw new ShippingException('PPL Response error HTTP Code: ' . $exception->getResponse()->getStatusCode());
        } catch (RequestException $exception) {
            throw new ShippingException('PPL tracking request error: ' . $exception->getMessage(), 0, $exception);
        }
    }

    public function getParcelTracking(string $consignmentId): ?ParcelTrackingInterface
    {
        $tracking = $this->getTracking($consignmentId);
        if (!$tracking instanceof \stdClass) {
            return null;
        }

        $delivered = $this->isDelivered($tracking->shipmentState);
        $deliveredDate = null;
        if ($delivered) {
            $string = $tracking->deliveryFeature?->delivDate;
            if (is_string($string)) {
                $deliveredDate = new \DateTimeImmutable($string);
            }
        }

        $entity = ParcelTracking::of(
            $delivered,
            $deliveredDate,
            $tracking->shipmentWeightInfo->weight ?? 0,
            $tracking->recipient->country ?? '',
            $tracking->recipient->zipCode ?? '',
            $tracking->shipmentNumber ?? '',
            $tracking->externalNumbers[0]->externalNumber ?? '',
        );

        if (!empty($tracking->trackAndTrace->events) && is_array($tracking->trackAndTrace->events)) {
            foreach (array_reverse($tracking->trackAndTrace->events) as $event) {
                $entity->addStatus(ParcelStatus::of(
                    '',
                    $tracking->depot ?? '',
                    $event->code ?? '',
                    $event->eventDate ?? '',
                    $event->name ?? '',
                    '',
                ));
            }
        }

        return $entity;
    }

    private function isDelivered(string $states): bool
    {
        $values = array_map('trim', explode(',', $states));
        return in_array(ShipmentState::Delivered->name, $values, true);
    }
}
