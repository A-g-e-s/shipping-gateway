<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\OneByAllegro;

use Ages\ShippingGateway\Common\CarrierInterface;
use Ages\ShippingGateway\Common\Data;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\OneByAllegro\Config\OneByAllegroConfig;
use Ages\ShippingGateway\OneByAllegro\Tracking\OneByAllegroTrackingMapper;
use Ages\ShippingGateway\OneByAllegro\Values\LabelFormat;
use Ages\ShippingGateway\OneByAllegro\Values\Method;
use Ages\ShippingGateway\Common\Http\AccessToken;
use Ages\ShippingGateway\Common\Http\HttpClient;
use Ages\ShippingGateway\Common\Http\HttpStatusException;
use Ages\ShippingGateway\Common\Http\TokenCache;
use GuzzleHttp\ClientInterface;

/**
 * One by Allegro (OneDelivery) Parcels API – https://api.onedelivery.tech/parcels/swagger.html
 */
class OneByAllegroApi implements CarrierInterface
{
    private const string ContentType = 'application/vnd.onedelivery.public.v1+json';
    private const int TimeoutSeconds = 30;

    private readonly HttpClient $http;
    private readonly TokenCache $tokens;

    public function __construct(protected readonly OneByAllegroConfig $config, ?ClientInterface $httpClient = null)
    {
        $this->http = new HttpClient('One by Allegro', $this->config->url, [
            'Accept' => self::ContentType . ', application/pdf, */*',
            'Accept-Language' => $this->config->language,
        ], ['timeout' => self::TimeoutSeconds], $httpClient, self::formatErrors(...));
        $this->tokens = new TokenCache(fn (): AccessToken => AccessToken::fromResponse(
            $this->requestJson('POST', Method::AuthToken->value, [
                'username' => $this->config->username,
                'password' => $this->config->password,
            ], [], false),
            'One by Allegro',
            'accessToken',
            'expiresIn',
        ));
    }

    /**
     * @param array<string, mixed> $shipment ShipmentRequest dle API
     * @return array{shipmentId: string, packageNumbers: string[]}
     */
    public function createShipmentRequest(array $shipment): array
    {
        $data = $this->requestJson('POST', Method::Shipments->value, $shipment);

        $shipmentId = $data['shipmentId'] ?? null;
        if (!is_string($shipmentId) && !is_int($shipmentId)) {
            throw new ShippingException('One by Allegro: shipmentId missing in response');
        }

        $packageNumbers = [];
        foreach (Data::arrays($data, 'packages') as $package) {
            $number = $package['packageNumber'] ?? null;
            if (is_string($number) || is_int($number)) {
                $packageNumbers[] = (string) $number;
            }
        }

        return ['shipmentId' => (string) $shipmentId, 'packageNumbers' => $packageNumbers];
    }

    /**
     * Štítky pro zásilky (max 50 zásilek / 100 balíků v jednom požadavku). Vrací raw PDF (nebo ZPL).
     *
     * @param string[] $shipmentIds
     */
    public function getLabels(array $shipmentIds, ?LabelFormat $format = null): string
    {
        $body = $this->request('POST', Method::Labels->value, [
            'shipmentIds' => array_values($shipmentIds),
            'format' => ($format ?? $this->config->labelFormat)->value,
        ]);

        return $this->decodeLabel($body);
    }

    /**
     * @return array<mixed> ShipmentDetailResponse
     */
    public function getShipment(string $shipmentId): array
    {
        return $this->requestJson('GET', Method::Shipments->value . '/' . rawurlencode($shipmentId));
    }

    /**
     * @param string[] $shipmentIds max 100
     * @return array<string, array<mixed>> shipmentId => seznam StatusResponse
     */
    public function getTrackingStatuses(array $shipmentIds): array
    {
        $data = $this->requestJson('GET', Method::Tracking->value, null, [
            'shipmentIds' => implode(',', $shipmentIds),
        ]);

        $result = [];
        foreach (Data::arrays($data, 'shipments') as $shipment) {
            $id = $shipment['shipmentId'] ?? null;
            $statuses = $shipment['statuses'] ?? [];
            if ((is_string($id) || is_int($id)) && is_array($statuses)) {
                $result[(string) $id] = $statuses;
            }
        }

        return $result;
    }

    /**
     * Seznam výdejních míst (BOX = One Box, PUDO = One Point) pro zemi CZ / SK / PL. Nevyžaduje přihlášení.
     *
     * @return array<mixed> seznam PointResponse
     */
    public function getPoints(string $countryCode): array
    {
        $data = $this->requestJson('GET', Method::Points->value, null, ['country' => strtoupper($countryCode)], false);
        $points = $data['points'] ?? [];
        return is_array($points) ? $points : [];
    }

    /**
     * Dostupné termíny svozu (pickupAvailabilityId ve tvaru Y-m-d) pro adresu odesílatele.
     *
     * @return string[]
     */
    public function getPickupProposals(?\DateTimeInterface $readyDate = null, bool $priority = false): array
    {
        $p = $this->config->pickupAddress;
        $query = [
            'postcode' => $p->zip,
            'countryCode' => strtoupper($p->country),
            'isPriority' => $priority ? 'true' : 'false',
        ];
        if ($readyDate !== null) {
            $query['readyDate'] = $readyDate->format('Y-m-d');
        }

        $data = $this->requestJson('GET', Method::PickupProposals->value, null, $query);
        $ids = $data['pickupAvailabilityIds'] ?? [];

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    /**
     * Objedná svoz zásilek kurýrem z pickupAddress. Bez $pickupAvailabilityId co nejdříve.
     *
     * @param string[] $shipmentIds
     * @return array{pickupId: string, pickupDate: ?\DateTimeImmutable}
     */
    public function createPickup(array $shipmentIds, ?string $pickupAvailabilityId = null): array
    {
        $payload = [
            'pickupAddress' => $this->buildPickupAddress(),
            'shipmentIds' => array_values($shipmentIds),
        ];
        if ($pickupAvailabilityId !== null) {
            $payload['pickupAvailabilityId'] = $pickupAvailabilityId;
        }

        $data = $this->requestJson('POST', Method::Pickups->value, $payload);
        $pickupId = $data['pickupId'] ?? null;
        if (!is_string($pickupId) && !is_int($pickupId)) {
            throw new ShippingException('One by Allegro: pickupId missing in response');
        }

        $date = $data['pickupDate'] ?? null;
        $pickupDate = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;

        return ['pickupId' => (string) $pickupId, 'pickupDate' => $pickupDate !== false ? $pickupDate : null];
    }

    public function deletePickup(string $pickupId): void
    {
        $this->request('DELETE', Method::Pickups->value . '/' . rawurlencode($pickupId));
    }

    public function getParcelTracking(string $consignmentId, ?\DateTimeInterface $createdAt = null): ?ParcelTrackingInterface
    {
        $statuses = $this->getTrackingStatuses([$consignmentId])[$consignmentId] ?? null;
        if ($statuses === null) {
            return null;
        }

        [$country, $weight] = $this->shipmentCountryAndWeight($consignmentId);
        return OneByAllegroTrackingMapper::tracking($consignmentId, $statuses, $country, $weight);
    }

    public function getTrackingUrl(string $consignmentId): string
    {
        return $this->config->trackingUrl . rawurlencode($consignmentId);
    }

    /**
     * @return array<string, string>
     */
    protected function buildPickupAddress(): array
    {
        $p = $this->config->pickupAddress;
        [$street, $number] = AddressFormatter::splitStreet($p->street, $p->houseNumber);

        return array_filter([
            'companyName' => mb_substr($p->name, 0, 150),
            'phoneNumber' => AddressFormatter::phone($p->phone, $p->country),
            'email' => $p->email !== '' ? mb_substr($p->email, 0, 50) : null,
            'postcode' => $p->zip,
            'city' => $p->city,
            'street' => $street,
            'streetNumber' => $number,
            'countryCode' => strtoupper($p->country),
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array{string, float}
     */
    private function shipmentCountryAndWeight(string $shipmentId): array
    {
        try {
            $detail = $this->getShipment($shipmentId);
        } catch (ShippingException) {
            return ['', 0.0];
        }

        $weight = 0.0;
        foreach (Data::arrays($detail, 'packages') as $package) {
            $weight += Data::float($package, 'weight');
        }

        return [Data::string($detail, ['receiver', 'countryCode']), $weight];
    }

    private function decodeLabel(string $body): string
    {
        if (str_starts_with($body, '%PDF') || str_starts_with(ltrim($body), '^XA')) {
            return $body;
        }

        // OpenAPI uvádí "format: byte" – pro jistotu i base64 (případně jako JSON string)
        $decoded = json_decode($body, true);
        $candidate = is_string($decoded) ? $decoded : trim($body, " \t\n\r\0\x0B\"");
        $binary = base64_decode($candidate, true);
        if ($binary !== false && $binary !== '') {
            return $binary;
        }

        if ($body === '') {
            throw new ShippingException('One by Allegro: empty label response');
        }

        return $body;
    }

    /**
     * @param array<mixed>|null $json
     * @param array<string, string> $query
     * @return array<mixed>
     */
    private function requestJson(string $method, string $path, ?array $json = null, array $query = [], bool $auth = true): array
    {
        return $this->http->decodeJson($this->request($method, $path, $json, $query, $auth), $method, $path);
    }

    /**
     * @param array<mixed>|null $json
     * @param array<string, string> $query
     * @throws HttpStatusException HTTP ≥ 400 (kód v getCode() – OneByAllegroShipmentHandler::waitForLabel)
     */
    private function request(string $method, string $path, ?array $json = null, array $query = [], bool $auth = true): string
    {
        $options = [];
        if ($json !== null) {
            $options['headers'] = ['Content-Type' => self::ContentType];
            $options['body'] = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        }
        if ($query !== []) {
            $options['query'] = $query;
        }

        return $this->http->send($method, $path, $options, $auth ? $this->tokens : null);
    }

    private static function formatErrors(string $body): string
    {
        $data = json_decode($body, true);
        $errors = is_array($data) ? ($data['errors'] ?? null) : null;
        if (!is_array($errors) || $errors === []) {
            return mb_substr($body, 0, 500);
        }

        $messages = [];
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }
            $text = $error['userMessage'] ?? $error['message'] ?? $error['code'] ?? '';
            $path = $error['path'] ?? null;
            $messages[] = (is_string($path) && $path !== '' && $path !== '-' ? $path . ': ' : '') . (is_string($text) ? $text : '');
        }

        return implode('; ', $messages);
    }
}
