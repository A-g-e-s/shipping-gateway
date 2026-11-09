<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\CzechPost\Tracking;

use Ages\ShippingGateway\Common\Data;
use Ages\ShippingGateway\Common\Tracking\DeliveredDate;
use Ages\ShippingGateway\Common\Tracking\ParcelStatus;
use Ages\ShippingGateway\Common\Tracking\ParcelTracking;

/**
 * Odpověď parcelStatus → společné tracking entity. Doručeno je stav 91, poškození Česká pošta nehlásí.
 */
final class CzechPostTrackingMapper
{
    private const string DeliveredCode = '91';

    private function __construct()
    {
    }

    /**
     * @param array<mixed> $response
     * @return ParcelTracking|null null, když odpověď neobsahuje zásilku se stavy
     */
    public static function tracking(array $response): ?ParcelTracking
    {
        $detail = Data::get($response, ['detail', 0]);
        foreach (['idParcel', 'countryOfDestination', 'weight'] as $required) {
            if (Data::get($detail, $required) === null) {
                return null;
            }
        }
        $statuses = Data::get($detail, 'parcelStatuses');
        if (!is_array($statuses)) {
            return null;
        }

        $tracking = new ParcelTracking(
            parcelNumber: Data::string($detail, 'idParcel'),
            deliveryCountryCode: Data::string($detail, 'countryOfDestination'),
            weight: Data::float($detail, 'weight'),
            deliveredDatePolicy: DeliveredDate::LastAdded,
        );

        // Česká pošta vrací stavy od nejnovějšího
        foreach (array_reverse(Data::arrays($statuses, [])) as $line) {
            $status = self::status($line);
            if ($status !== null) {
                $tracking->addStatus($status);
            }
        }

        return $tracking;
    }

    /**
     * @param array<mixed> $line
     */
    public static function status(array $line): ?ParcelStatus
    {
        if (!isset($line['id'], $line['date'], $line['text'])) {
            return null;
        }

        $code = Data::string($line, 'id');
        return new ParcelStatus(
            statusCode: $code,
            statusDescription: Data::string($line, 'text'),
            statusDate: Data::date($line['date'], new \DateTimeZone('Europe/Prague')),
            depotCity: Data::nonEmptyString($line, 'name'),
            depotCode: Data::nonEmptyString($line, 'postCode'),
            delivered: $code === self::DeliveredCode,
        );
    }
}
