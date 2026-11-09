<?php

namespace Ages\ShippingGateway\CzechPost;

use Ages\ShippingGateway\Common\CarrierInterface;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\CzechPost\Config\CzechPostConfig;
use Ages\ShippingGateway\CzechPost\Tracking\CzechPostTrackingMapper;
use Ages\ShippingGateway\CzechPost\Entity\Values\Method;
use Ages\ShippingGateway\Common\Http\HttpClient;
use Ages\ShippingGateway\Common\ShippingException;
use GuzzleHttp\ClientInterface;
use Random\RandomException;

class CzechPostApi implements CarrierInterface
{
    public const string TrackUrl = 'https://www.postaonline.cz/trackandtrace/-/zasilka/';

    private readonly HttpClient $http;

    public function __construct(protected readonly CzechPostConfig $config, ?ClientInterface $httpClient = null)
    {
        $this->http = new HttpClient('Czech Post', $this->config->url, [], [
            'verify' => $this->config->certificatePath,
            'timeout' => 15.0,
            'connect_timeout' => 10.0,
        ], $httpClient);
    }

    /**
     * @param Method       $method
     * @param array<mixed> $data
     * @return array<mixed>
     * @throws CzechPostException
     */
    private function getResponse(Method $method, array $data): array
    {
        $bodyString = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($bodyString === false) {
            throw new CzechPostException('Incorrect request data');
        }

        $contentHash = hash('sha256', $bodyString);
        $timestamp = time();
        $nonce = self::uuidV4();
        $signature = base64_encode(hash_hmac('sha256', "{$contentHash};{$timestamp};{$nonce}", $this->config->secretKey, true));
        $headers = [
            'Content-Type' => 'application/json;charset=UTF-8',
            'Api-Token' => $this->config->apiToken,
            'Authorization-Timestamp' => (string)$timestamp,
            'Authorization-Content-SHA256' => $contentHash,
            'Authorization' => sprintf('CP-HMAC-SHA256 nonce="%s" signature="%s"', $nonce, $signature),
        ];
        try {
            $response = $this->http->request('POST', $method->value, [
                'headers' => $headers,
                'body' => $bodyString,
            ]);
        } catch (ShippingException $e) {
            throw new CzechPostException('HTTP client error: ' . $e->getMessage(), 0, ['exception' => (string) ($e->getPrevious() ?? $e)]);
        }

        $status = $response->getStatusCode();
        $body = (string)$response->getBody();

        if ($status < 200 || $status >= 300) {
            throw new CzechPostException('HTTP Status Error', $status, $body);
        }
        if ($body === '') {
            throw new CzechPostException('Empty response', 500, ['error' => 'empty body']);
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new CzechPostException('Response JSON Error', 500, [
                'response_content' => $body,
                'json_error' => json_last_error_msg(),
            ]);
        }
        return $json;
    }

    /**
     * @throws CzechPostException
     */
    private static function uuidV4(): string
    {
        try {
            $d = random_bytes(16);
            $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
            $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
        } catch (RandomException) {
            throw new CzechPostException('Random generation error');
        }
    }

    /**
     * @param array<mixed>  $parcelServiceHeader
     * @param array<mixed>  $parcelServiceData
     * @param ?array<mixed> $multipartParcelData
     * @throws CzechPostException
     */
    protected function parcelService(array $parcelServiceHeader, array $parcelServiceData, ?array $multipartParcelData = null): mixed
    {
        $data = [
            'parcelServiceHeader' => $parcelServiceHeader,
            'parcelServiceData' => $parcelServiceData
        ];
        if (!empty($multipartParcelData)) {
            $data['multipartParcelData'] = $multipartParcelData;
        }
        return $this->getResponse(Method::SendConsignment, $data);
    }

    /**
     * @param string|array<mixed> $parcel_ids
     * @return array<mixed>
     * @throws CzechPostException
     */
    protected function parcelStatusesApi(string|array $parcel_ids, string $language = 'cz'): array
    {
        if (!is_array($parcel_ids)) {
            $parcel_ids = [$parcel_ids];
        }
        return $this->getResponse(Method::Tracking, [
            'parcelIds' => $parcel_ids,
            'language' => $language,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function prepareParcelServiceHeader(): array
    {
        return [
            'parcelServiceHeaderCom' => [
                'transmissionDate' => date('Y-m-d'),
                'customerID' => $this->config->customerId,
                'postCode' => $this->config->postCode,
                'locationNumber' => $this->config->locationNumber,
            ],
            'printParams' => [
                'idForm' => 101,
                'shiftHorizontal' => 0,
                'shiftVertical' => 0,
            ]
        ];
    }

    /**
     * Chyby API (i neplatný podpis) vrací null – tracking České pošty je jen informativní.
     */
    public function getParcelTracking(string $consignmentId, ?\DateTimeInterface $createdAt = null): ?ParcelTrackingInterface
    {
        try {
            return CzechPostTrackingMapper::tracking($this->parcelStatusesApi($consignmentId));
        } catch (\Exception) {
            return null;
        }
    }

    public function getTrackingUrl(string $consignmentId): string
    {
        return self::TrackUrl . rawurlencode($consignmentId);
    }
}
