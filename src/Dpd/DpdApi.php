<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Dpd;

use Ages\ShippingGateway\Common\CarrierInterface;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\Dpd\Config\DpdConfig;
use Ages\ShippingGateway\Dpd\Tracking\DpdParcelStatus;
use Ages\ShippingGateway\Dpd\Tracking\DpdParcelTracking;
use Ages\ShippingGateway\Dpd\Values\LabelFormat;
use Ages\ShippingGateway\Dpd\Values\Method;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * DPD CZ GeoAPI – https://geoapi.dpd.cz/v1/swagger/ (tracking z /v2)
 */
class DpdApi implements CarrierInterface
{
    private const int TimeoutSeconds = 60;
    private const int HttpNotFound = 404;

    private Client $httpClient;

    public function __construct(protected readonly DpdConfig $config)
    {
        $this->httpClient = new Client([
            'http_errors' => false,
            'timeout' => self::TimeoutSeconds,
        ]);
    }

    /**
     * Založí zásilky (max 50 v jednom požadavku).
     *
     * @param array<int, array<string, mixed>> $shipments NewShipment dle API
     * @return array<mixed> seznam Shipment
     */
    public function createShipments(array $shipments): array
    {
        return $this->requestJson('POST', Method::Shipments->value, array_values($shipments));
    }

    /**
     * PDF se štítky balíků (podle čísla balíku, 14 číslic).
     *
     * @param string[] $parcelNumbers
     */
    public function getLabels(array $parcelNumbers, ?LabelFormat $format = null): string
    {
        $payload = ($format ?? $this->config->labelFormat)->printSpec();
        $payload['parcels'] = array_map(static fn (string $number): array => ['parcelNumber' => $number], array_values($parcelNumbers));

        $body = $this->request('POST', Method::Labels->value, $payload, 'application/pdf');
        return self::decodePdf($body);
    }

    /**
     * Události balíku z GeoAPI v2. Null, když balík DPD (zatím) nezná.
     *
     * @return array<mixed>|null SuccessfulTrackingResult
     */
    public function getTracking(string $parcelNumber): ?array
    {
        try {
            return $this->requestJson('GET', Method::Parcels->value . '/' . rawurlencode($parcelNumber) . '/tracking');
        } catch (ShippingException $e) {
            if ($e->getCode() === self::HttpNotFound) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Zákazníci (DSW) a jejich registrované adresy (it4emId pro senderAddressId / svoz).
     *
     * @return array<mixed>
     */
    public function getMe(): array
    {
        return $this->requestJson('GET', Method::Me->value);
    }

    /**
     * @return array<mixed> dostupné kombinace služeb
     */
    public function getShippingServices(): array
    {
        return $this->requestJson('GET', Method::ShippingServices->value);
    }

    /**
     * Objedná kurýra na registrovanou adresu senderAddressId – vyzvedne všechny balíky s vytištěným štítkem.
     *
     * @return array<mixed> seznam PickupOrder (id, statusCode, statusText …)
     */
    public function createPickupOrder(?\DateTimeInterface $date = null, ?string $note = null): array
    {
        if ($this->config->senderAddressId === null) {
            throw new ShippingException('DPD: pickup order needs senderAddressId (it4emId from GET /me) in DpdConfig');
        }

        $order = ['customerAddress' => ['it4emId' => $this->config->senderAddressId]];
        if ($date !== null) {
            $order['date'] = $date->format('Y-m-d');
        }
        if ($note !== null && trim($note) !== '') {
            $order['note'] = mb_substr(trim($note), 0, 200);
        }

        return $this->requestJson('POST', Method::PickupOrders->value, [$order]);
    }

    /**
     * @return array<mixed> PickupOrder
     */
    public function getPickupOrder(int $pickupOrderId): array
    {
        return $this->requestJson('GET', Method::PickupOrders->value . '/' . $pickupOrderId);
    }

    public function cancelPickupOrder(int $pickupOrderId): void
    {
        $this->request('DELETE', Method::PickupOrders->value . '/' . $pickupOrderId);
    }

    public function getParcelTracking(string $consignmentId): ?ParcelTrackingInterface
    {
        $result = $this->getTracking($consignmentId);
        if ($result === null) {
            return null;
        }

        $info = is_array($result['parcelInfo'] ?? null) ? $result['parcelInfo'] : [];
        $country = self::dig($info, 'receiver', 'address', 'country', 'isoAlpha2');
        $number = $info['parcelNumber'] ?? $consignmentId;
        $tracking = DpdParcelTracking::of(
            is_string($number) || is_int($number) ? (string) $number : $consignmentId,
            is_string($country) ? $country : '',
        );

        $statuses = [];
        $events = $result['parcelEvents'] ?? [];
        if (is_array($events)) {
            foreach ($events as $event) {
                if (is_array($event)) {
                    $statuses[] = DpdParcelStatus::of($event);
                }
            }
        }
        usort($statuses, static fn (DpdParcelStatus $a, DpdParcelStatus $b): int => $a->statusDate <=> $b->statusDate);
        foreach ($statuses as $status) {
            $tracking->addStatus($status);
        }

        return $tracking;
    }

    public function getTrackingUrl(string $consignmentId): string
    {
        return $this->config->trackingUrl . rawurlencode($consignmentId);
    }

    /**
     * @param array<mixed> $data
     */
    private static function dig(array $data, string ...$keys): mixed
    {
        $value = $data;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    private static function decodePdf(string $body): string
    {
        if (str_starts_with($body, '%PDF')) {
            return $body;
        }

        // GeoAPI vrací PDF jako base64 (případně JSON string)
        $decoded = json_decode($body, true);
        $candidate = is_string($decoded) ? $decoded : trim($body, " \t\n\r\0\x0B\"");
        $binary = base64_decode($candidate, true);
        if ($binary === false || !str_starts_with($binary, '%PDF')) {
            throw new ShippingException('DPD: label response is not a PDF: ' . mb_substr($body, 0, 200));
        }

        return $binary;
    }

    /**
     * @param array<mixed>|null $json
     * @return array<mixed>
     */
    private function requestJson(string $method, string $path, ?array $json = null): array
    {
        $body = $this->request($method, $path, $json);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new ShippingException('DPD ' . $method . ' ' . $path . ': invalid JSON response');
        }
        return $data;
    }

    /**
     * @param array<mixed>|null $json
     */
    private function request(string $method, string $path, ?array $json = null, string $accept = 'application/json'): string
    {
        $options = [
            'headers' => [
                'x-api-key' => $this->config->apiKey,
                'Accept' => $accept,
            ],
        ];
        if ($json !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        try {
            $response = $this->httpClient->request($method, rtrim($this->config->url, '/') . $path, $options);
        } catch (GuzzleException $e) {
            throw new ShippingException('DPD ' . $method . ' ' . $path . ' request error: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($status >= 200 && $status < 300) {
            return $body;
        }

        throw new ShippingException(sprintf('DPD %s %s HTTP %d: %s', $method, $path, $status, self::formatError($body)), $status);
    }

    private static function formatError(string $body): string
    {
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['message'])) {
            return mb_substr($body, 0, 500);
        }

        $message = (is_string($data['code'] ?? null) ? $data['code'] . ': ' : '') . (is_string($data['message']) ? $data['message'] : '');

        $details = [];
        $description = $data['description'] ?? null;
        if (is_string($description) || is_int($description)) {
            $details[] = (string) $description;
        } elseif (is_array($description) && array_is_list($description)) {
            foreach ($description as $error) {
                if (!is_array($error)) {
                    continue;
                }
                $path = is_string($error['path'] ?? null) ? $error['path'] : '';
                $text = is_string($error['message'] ?? null) ? $error['message'] : '';
                if ($text === '' && is_array($error['unionErrors'] ?? null)) {
                    foreach ($error['unionErrors'] as $union) {
                        $issues = self::dig(is_array($union) ? $union : [], 'issues');
                        foreach (is_array($issues) ? $issues : [] as $issue) {
                            if (is_array($issue) && is_string($issue['message'] ?? null)) {
                                $text .= ($text !== '' ? ', ' : '') . (is_string($issue['path'] ?? null) ? $issue['path'] . ' ' : '') . $issue['message'];
                            }
                        }
                    }
                }
                $details[] = trim($path . ': ' . $text, ': ');
            }
        } elseif (is_array($description)) {
            $details[] = (string) json_encode($description, JSON_UNESCAPED_UNICODE);
        }

        return $message . ($details !== [] ? ' (' . implode('; ', $details) . ')' : '');
    }
}
