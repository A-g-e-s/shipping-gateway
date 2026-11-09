<?php

namespace Ages\ShippingGateway\Ppl;

use Ages\ShippingGateway\Common\CarrierInterface;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\Ppl\Config\PplConfig;
use Ages\ShippingGateway\Ppl\Entity\AddressEntity;
use Ages\ShippingGateway\Ppl\Entity\ParcelEntity;
use Ages\ShippingGateway\Ppl\Values\Method;
use Ages\ShippingGateway\Ppl\Tracking\PplTrackingMapper;
use Ages\ShippingGateway\Common\Http\AccessToken;
use Ages\ShippingGateway\Common\Http\HttpClient;
use Ages\ShippingGateway\Common\Http\HttpStatusException;
use Ages\ShippingGateway\Common\Http\TokenCache;
use GuzzleHttp\ClientInterface;

class PplApi implements CarrierInterface
{
    public const string TrackUrl = 'https://www.ppl.cz/vyhledat-zasilku?shipmentId=';

    private const string CarrierName = 'PPL';

    private readonly HttpClient $http;
    private readonly TokenCache $tokens;

    public function __construct(protected readonly PplConfig $config, ?ClientInterface $httpClient = null)
    {
        $this->http = new HttpClient(self::CarrierName, $this->config->url, [], [], $httpClient, self::formatErrors(...));
        // OAuth client_credentials, token platí 30 min (expires_in)
        $this->tokens = new TokenCache(fn (): AccessToken => AccessToken::fromResponse(
            $this->http->sendJson('POST', Method::AccessToken->value, [
                'form_params' => [
                    'client_id' => $this->config->clientId,
                    'client_secret' => $this->config->clientSecret,
                    'grant_type' => $this->config->grantType,
                    'scope' => $this->config->scope,
                ],
            ]),
            self::CarrierName,
        ));
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

    /**
     * Jedna dávka s jednou nebo více zásilkami.
     */
    public function createBatch(ParcelEntity $parcelEntity, ParcelEntity ...$moreEntities): string
    {
        $path = Method::ShipmentBatch->value;
        $response = $this->http->request('POST', $path, [
            'json' => ParcelEntity::batch($parcelEntity, ...$moreEntities),
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ], $this->tokens);

        if ($response->getStatusCode() === 201) {
            $location = $response->getHeader('Location')[0] ?? null;
            if (is_string($location) && $location !== '') {
                return basename($location);
            }
            throw new ShippingException('PPL createBatch: missing Location header in response');
        }

        if ($response->getStatusCode() >= 400) {
            throw $this->http->statusException('POST', $path, $response);
        }

        throw new ShippingException('PPL: Incorrect response HTTP Code: ' . $response->getStatusCode());
    }

    /**
     * @throws HttpStatusException 404, dokud štítek není připravený (PplShipmentHandler::waitForLabel)
     */
    public function getLabel(string $url): string
    {
        return $this->http->send('GET', $url, [
            'headers' => ['Content-Type' => 'text/plain'],
        ], $this->tokens);
    }

    public function getBatchLabel(string $batchId): string
    {
        return $this->http->send('GET', Method::ShipmentBatch->value . '/' . $batchId . '/' . Method::ShipmentBatchLabel->value, [
            'query' => [
                'limit' => 100,
                'offset' => 0,
            ],
            'headers' => ['Content-Type' => 'text/plain'],
        ], $this->tokens);
    }

    public function getStatus(string $batchId): ?\stdClass
    {
        $body = $this->http->send('GET', Method::ShipmentBatch->value . '/' . $batchId, [
            'headers' => ['Content-Type' => 'text/plain'],
        ], $this->tokens);
        $response = json_decode($body);

        if (!$response instanceof \stdClass || !isset($response->items) || !is_array($response->items) || !isset($response->items[0]) || !$response->items[0] instanceof \stdClass) {
            throw new ShippingException('PPL: invalid batch status response');
        }

        // dávka může mít víc zásilek (export s dobírkou) – hotovo je, až jsou hotové všechny
        $complete = true;
        foreach ($response->items as $item) {
            $importState = $item instanceof \stdClass ? ($item->importState ?? null) : null;
            if ($importState === 'Error') {
                $detail = array_filter([
                    is_scalar($item->errorCode ?? null) ? (string) $item->errorCode : null,
                    is_scalar($item->errorMessage ?? null) ? (string) $item->errorMessage : null,
                ]);
                throw new ShippingException('PPL: batch processing failed for batch ' . $batchId . ($detail !== [] ? ': ' . implode(' ', $detail) : ''));
            }
            if ($importState !== 'Complete') {
                $complete = false;
            }
        }

        return $complete ? $response : null;
    }

    public function getTracking(string $consignmentId): ?\stdClass
    {
        $path = Method::Shipment->value;
        $response = $this->http->request('GET', $path, [
            'query' => [
                'ShipmentNumbers' => [$consignmentId],
                'Limit' => 10,
                'Offset' => 0,
            ],
            'headers' => [
                'Content-Type' => 'text/plain',
                'Accept-Language' => 'cs-CZ',
            ],
        ], $this->tokens);

        if ($response->getStatusCode() >= 400) {
            throw $this->http->statusException('GET', $path, $response);
        }
        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $json = json_decode((string) $response->getBody());
        if (is_array($json) && isset($json[0]) && $json[0] instanceof \stdClass) {
            return $json[0];
        }
        return null;
    }

    public function getParcelTracking(string $consignmentId, ?\DateTimeInterface $createdAt = null): ?ParcelTrackingInterface
    {
        $tracking = $this->getTracking($consignmentId);
        return $tracking !== null ? PplTrackingMapper::tracking($tracking) : null;
    }

    public function getTrackingUrl(string $consignmentId): string
    {
        return self::TrackUrl . rawurlencode($consignmentId);
    }

    /**
     * Chybová odpověď PPL: {"errors": {"pole": ["zpráva", …]}} → „Bad Request<br />-> pole (zpráva)“.
     */
    private static function formatErrors(string $body): string
    {
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['errors']) || !is_array($data['errors'])) {
            return HttpClient::sample($body);
        }

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

        $title = is_string($data['title'] ?? null) ? $data['title'] : 'Bad Request';
        return $messages !== [] ? $title . '<br />' . implode('<br />', $messages) : HttpClient::sample($body);
    }
}
