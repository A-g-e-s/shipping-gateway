<?php

namespace Ages\ShippingGateway\Gls;

use Ages\ShippingGateway\Common\CarrierInterface;
use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Ages\ShippingGateway\Gls\Config\GlsConfig;
use Ages\ShippingGateway\Gls\Entity\AddressEntity;
use Ages\ShippingGateway\Gls\Entity\ParcelEntity;
use Ages\ShippingGateway\Gls\Entity\ParcelStatus;
use Ages\ShippingGateway\Gls\Entity\ParcelTracking;
use Ages\ShippingGateway\Gls\Values\Method;
use Ages\ShippingGateway\Gls\Values\RequestObject;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Tracy\Debugger;
use UnexpectedValueException;

class GlsApi implements CarrierInterface
{
    private Client $httpClient;

    public function __construct(protected readonly GlsConfig $config)
    {
        $this->httpClient = new Client();
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

    protected function printLabels(ParcelEntity $parcels): false|\stdClass
    {
        $request = $this->getRequestPayload([$parcels->toArray()], RequestObject::PrintLabels);
        $response = $this->getResponse(Method::PrintLabels, $request);
        if (is_string($response)) {
            $data = json_decode($response, false);
            if ($data instanceof \stdClass) {
                return $data;
            }
        }
        Debugger::log('GLS Unexpected response');
        return false;
    }

    /**
     * @return array<string, int>
     */
    protected function prepareParcels(ParcelEntity $parcels): mixed
    {
        $request = $this->getRequestPayload([$parcels->toArray()], RequestObject::PrepareLabels);
        $response = $this->getResponse(Method::PrepareLabels, $request);
        if (is_string($response)) {
            $data = json_decode($response, false);
            if ($data instanceof \stdClass) {
                $parcelIdList = [];
                if (isset($data->PrepareLabelsError) && isset($data->ParcelInfoList) && count($data->PrepareLabelsError) === 0 && count($data->ParcelInfoList) > 0) {
                    foreach ($data->ParcelInfoList as $parcelInfo) {
                        $parcelIdList[$parcelInfo->ClientReference] = $parcelInfo->ParcelId;
                    }
                    return $parcelIdList;
                }
            }
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
        $response = $this->getResponse(Method::PrintedLabels, $request);
        if (is_string($response)) {
            $data = json_decode($response);
            if ($data instanceof \stdClass && isset($data->Labels)) {
                return implode(array_map('chr', $data->Labels));
            }
        }
        throw new UnexpectedValueException('Unexpected response');
    }

    protected function parcelStatuses(string $parcelId): \stdClass
    {
        $statusRequest = [
            'ParcelNumber' => $parcelId,
            'ReturnPOD' => true,
            'LanguageIsoCode' => 'CZ'
        ];
        $request = $this->getRequestPayload($statusRequest, RequestObject::ParcelStatuses);
        $response = $this->getResponse(Method::ParcelStatuses, $request);
        if (is_string($response)) {
            $data = json_decode($response);
            if ($data instanceof \stdClass) {
                return $data;
            }
        }
        throw new UnexpectedValueException('Unexpected response');
    }

    public function getParcelTracking(string $consignmentId): ?ParcelTrackingInterface
    {
        try {
            $tracking = $this->parcelStatuses($consignmentId);
            if (isset($tracking->DeliveryCountryCode, $tracking->DeliveryZipCode, $tracking->ParcelNumber, $tracking->ClientReference, $tracking->Weight)) {
                $parcelTracking = ParcelTracking::of(
                    $tracking->DeliveryCountryCode,
                    $tracking->DeliveryZipCode,
                    $tracking->ParcelNumber,
                    $tracking->ClientReference,
                    $tracking->Weight,
                );
                foreach ($tracking->ParcelStatusList as $statusLine) {
                    if (isset($statusLine->DepotCity, $statusLine->DepotNumber, $statusLine->StatusCode, $statusLine->StatusDate, $statusLine->StatusDescription, $statusLine->StatusInfo)) {
                        $parcelTracking->addStatus(ParcelStatus::of(
                            $statusLine->DepotCity,
                            $statusLine->DepotNumber,
                            $statusLine->StatusCode,
                            $statusLine->StatusDate,
                            $statusLine->StatusDescription,
                            $statusLine->StatusInfo,
                        ));
                    }
                }
                return $parcelTracking;
            }
        } catch (\Exception $exception) {
            Debugger::log($exception);
        }
        return null;
    }

    /**
     * @param array<mixed>  $dataList
     * @param RequestObject $object
     * @return array<mixed>
     */
    private function getRequestPayload(array $dataList, RequestObject $object): array
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
     */
    private function getResponse(Method $method, array $payload): null|string
    {
        $url = $this->config->url . $method->value;
        try {
            $response = $this->httpClient->post($url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ],
                'json' => $payload,
                'timeout' => 600,
                'connect_timeout' => 15,
                'http_errors' => false,
                'verify' => true,
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $body = $response->getBody();
            $text = $body->getContents();
            $body->close();
            return $text;
        } catch (GuzzleException $e) {
            Debugger::log($e);
            return null;
        }
    }
}
