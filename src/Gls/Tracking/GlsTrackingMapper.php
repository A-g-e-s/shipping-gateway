<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Gls\Tracking;

use Ages\ShippingGateway\Common\Data;
use Ages\ShippingGateway\Common\Tracking\DeliveredDate;
use Ages\ShippingGateway\Common\Tracking\ParcelStatus;
use Ages\ShippingGateway\Common\Tracking\ParcelTracking;
use Ages\ShippingGateway\Gls\Entity\Values\StatusCode;

/**
 * Odpověď GetParcelStatuses → společné tracking entity. Doručení / poškození podle číselníku StatusCode.
 */
final class GlsTrackingMapper
{
    private function __construct()
    {
    }

    /**
     * @return ParcelTracking|null null, když GLS zásilku nezná (prázdné ParcelNumber)
     */
    public static function tracking(\stdClass $response, string $consignmentId): ?ParcelTracking
    {
        $parcelNumber = Data::string($response, 'ParcelNumber', $consignmentId);
        if ($parcelNumber === '') {
            return null;
        }

        $tracking = new ParcelTracking(
            parcelNumber: $parcelNumber,
            deliveryCountryCode: Data::string($response, 'DeliveryCountryCode'),
            weight: Data::float($response, 'Weight'),
            deliveryZipCode: Data::string($response, 'DeliveryZipCode'),
            clientReference: Data::string($response, 'ClientReference'),
            deliveredDatePolicy: DeliveredDate::LastAdded,
        );

        foreach (Data::objects($response, 'ParcelStatusList') as $line) {
            $status = self::status($line);
            if ($status !== null) {
                $tracking->addStatus($status);
            }
        }

        return $tracking;
    }

    public static function status(\stdClass $line): ?ParcelStatus
    {
        $statusCode = Data::string($line, 'StatusCode');
        $statusDate = Data::string($line, 'StatusDate');
        $statusDescription = Data::string($line, 'StatusDescription');
        if ($statusCode === '' && $statusDate === '' && $statusDescription === '') {
            return null;
        }

        $code = StatusCode::getStatusCode($statusCode);

        return new ParcelStatus(
            statusCode: $statusCode,
            statusDescription: $statusDescription,
            statusDate: self::parseDate($statusDate),
            depotCity: Data::string($line, 'DepotCity'),
            depotCode: Data::string($line, 'DepotNumber'),
            statusInfo: Data::nonEmptyString($line, 'StatusInfo'),
            customInfo: $code !== false ? $code->value : null,
            delivered: $code !== false && StatusCode::isDelivered($code),
            damaged: $code !== false && StatusCode::isDamaged($code),
        );
    }

    /**
     * GLS posílá datum i ve formátu WCF „/Date(1700000000000+0100)/“.
     */
    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        if (preg_match('~/Date\((\d+)([+-]\d{4})?\)/~', $value, $matches) === 1) {
            $date = new \DateTimeImmutable('@' . ((int) $matches[1] / 1000));
            return isset($matches[2]) ? $date->setTimezone(new \DateTimeZone($matches[2])) : $date;
        }
        return Data::date($value);
    }
}
