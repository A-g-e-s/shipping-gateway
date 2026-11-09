<?php

namespace Ages\ShippingGateway\Gls;

use Ages\ShippingGateway\Common\CarrierInterface;
use Ages\ShippingGateway\Common\Data;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\Common\ShippingException;
use Ages\ShippingGateway\Gls\Config\GlsConfig;
use Ages\ShippingGateway\Gls\Entity\AddressEntity;
use Ages\ShippingGateway\Gls\Entity\ParcelEntity;
use Ages\ShippingGateway\Gls\Tracking\GlsTrackingMapper;
use Ages\ShippingGateway\Gls\Values\Method;
use Ages\ShippingGateway\Gls\Values\RequestObject;
use Ages\ShippingGateway\Common\Http\HttpClient;
use GuzzleHttp\ClientInterface;
use UnexpectedValueException;

class GlsApi implements CarrierInterface
{
    public const string TrackUrl = 'https://gls-group.com/CZ/cs/sledovani-zasilek/?match=';

    /** PrintLabels umí trvat dlouho – výchozí timeout, lze přepsat v GlsConfig::$timeout. */
    public const float DefaultTimeoutSeconds = 120.0;

    private readonly HttpClient $http;

    public function __construct(protected readonly GlsConfig $config, ?ClientInterface $httpClient = null)
    {
        $this->http = new HttpClient('GLS', $this->config->url, [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], [
            'timeout' => $this->config->timeout ?? self::DefaultTimeoutSeconds,
            'connect_timeout' => 15.0,
        ], $httpClient);
    }

    protected function getPickupAddress(): AddressEntity
    {
        $p = $this->config->pickupAddress;
        return AddressEntity::of(
            $p->name,
            $p->street,
            $p->city,
            $p->zip,
            $p->country,
            $p->houseNumber,
            '',
            null,
            $p->phone,
            $p->email,
        );
    }

    protected function printLabels(ParcelEntity $parcels): \stdClass
    {
        $request = $this->getRequestPayload([$parcels->toArray()], RequestObject::PrintLabels);
        $data = json_decode($this->getResponse(Method::PrintLabels, $request), false);
        if ($data instanceof \stdClass) {
            return $data;
        }
        throw new ShippingException('GLS: Unexpected response from printLabels');
    }

    /**
     * @return array<string, int>
     */
    protected function prepareParcels(ParcelEntity $parcels): array
    {
        $request = $this->getRequestPayload([$parcels->toArray()], RequestObject::PrepareLabels);
        $data = json_decode($this->getResponse(Method::PrepareLabels, $request), false);
        $infoList = Data::objects($data, 'ParcelInfoList');
        if ($data instanceof \stdClass && Data::array($data, 'PrepareLabelsError') === [] && $infoList !== []) {
            $parcelIdList = [];
            foreach ($infoList as $parcelInfo) {
                $parcelIdList[Data::string($parcelInfo, 'ClientReference')] = (int) Data::float($parcelInfo, 'ParcelId');
            }
            return $parcelIdList;
        }
        throw new UnexpectedValueException('Unexpected response');
    }

    protected function getLabels(int $parcelId): string
    {
        $labelsRequest = [
            'ParcelIdList' => [$parcelId],
            'ShowPrintDialog' => false,
            'TypeOfPrinter' => 'Connect'
        ];
        $request = $this->getRequestPayload($labelsRequest, RequestObject::PrintedLabels);
        $data = json_decode($this->getResponse(Method::PrintedLabels, $request));
        $bytes = array_filter(Data::array($data, 'Labels'), 'is_int');
        if ($bytes !== []) {
            return implode(array_map(chr(...), $bytes));
        }
        throw new UnexpectedValueException('Unexpected response');
    }

    protected function parcelStatuses(string $parcelId): \stdClass
    {
        $statusRequest = [
            'ParcelNumber' => $parcelId,
            'ReturnPOD' => true,
            'LanguageIsoCode' => 'CS'
        ];
        $request = $this->getRequestPayload($statusRequest, RequestObject::ParcelStatuses);
        $data = json_decode($this->getResponse(Method::ParcelStatuses, $request));
        if ($data instanceof \stdClass) {
            return $data;
        }
        throw new UnexpectedValueException('Unexpected response');
    }

    public function getParcelTracking(string $consignmentId, ?\DateTimeInterface $createdAt = null): ?ParcelTrackingInterface
    {
        $tracking = $this->parcelStatuses($consignmentId);
        $statusErrors = Data::objects($tracking, 'GetParcelStatusErrors');
        if ($statusErrors !== []) {
            throw new ShippingException('GLS: ' . self::formatErrors($statusErrors));
        }

        return GlsTrackingMapper::tracking($tracking, $consignmentId);
    }

    /**
     * @param \stdClass[] $errors GetParcelStatusErrors / PrintLabelsErrorList
     */
    protected static function formatErrors(array $errors): string
    {
        return implode(', ', array_map(
            static fn(\stdClass $error): string => sprintf(
                '%s (%s)',
                Data::string($error, 'ErrorCode', 'unknown'),
                Data::string($error, 'ErrorDescription', 'unknown error'),
            ),
            $errors,
        ));
    }

    public function getTrackingUrl(string $consignmentId): string
    {
        return self::TrackUrl . rawurlencode($consignmentId);
    }

    /**
     * @param array<mixed>  $dataList
     * @param RequestObject $object
     * @return array<mixed>
     */
    protected function getRequestPayload(array $dataList, RequestObject $object): array
    {
        $bytes = unpack('C*', hash('sha512', $this->config->password, true));
        $passwordBytes = array_values(is_array($bytes) ? $bytes : []);

        $base = [
            'Username' => $this->config->username,
            'Password' => $passwordBytes,
        ];

        return match ($object) {
            RequestObject::PrintLabels => $base + [
                'ParcelList' => $dataList,
                'PrintPosition' => 1,
                'ShowPrintDialog' => 0,
                'TypeOfPrinter' => 'Connect',
            ],
            RequestObject::PrepareLabels => $base + [
                'ParcelList' => $dataList,
            ],
            RequestObject::PrintedLabels,
            RequestObject::ParcelStatuses => array_merge($base, $dataList),
        };
    }

    /**
     * @param Method       $method
     * @param array<mixed> $payload
     * @throws ShippingException
     */
    protected function getResponse(Method $method, array $payload): string
    {
        return $this->http->send('POST', $method->value, ['json' => $payload]);
    }
}
