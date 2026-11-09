<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\OneByAllegro\Tracking;

use Ages\ShippingGateway\Common\Data;
use Ages\ShippingGateway\Common\Tracking\ParcelStatus;
use Ages\ShippingGateway\Common\Tracking\ParcelTracking;
use Ages\ShippingGateway\OneByAllegro\Values\StatusCode;

/**
 * Stavy z /parcels/tracking → společné tracking entity, seřazené podle data.
 * Doručeno DOR, poškozeno USZ (poškozená) a ZAG (ztracená), ZDN* = vráceno odesílateli.
 */
final class OneByAllegroTrackingMapper
{
    private function __construct()
    {
    }

    /**
     * @param array<mixed> $statuses seznam StatusResponse
     */
    public static function tracking(string $shipmentId, array $statuses, string $deliveryCountryCode = '', float $weight = 0.0): ParcelTracking
    {
        $entities = array_map(self::status(...), Data::arrays($statuses, []));
        usort($entities, static fn (ParcelStatus $a, ParcelStatus $b): int => $a->statusDate <=> $b->statusDate);

        $tracking = new ParcelTracking($shipmentId, $deliveryCountryCode, $weight);
        foreach ($entities as $entity) {
            $tracking->addStatus($entity);
        }
        return $tracking;
    }

    /**
     * @param array<mixed> $status StatusResponse
     */
    public static function status(array $status): ParcelStatus
    {
        $code = is_string($status['code'] ?? null) ? $status['code'] : '';
        $delivered = $code === StatusCode::Delivered;

        return new ParcelStatus(
            statusCode: $code,
            statusDescription: is_string($status['description'] ?? null) && $status['description'] !== '' ? $status['description'] : $code,
            statusDate: Data::date($status['date'] ?? null),
            depotCity: Data::nonEmptyString($status, 'place'),
            customInfo: match (true) {
                $delivered => 'Doručeno',
                $code === StatusCode::Damaged => 'Poškozeno',
                $code === StatusCode::Lost => 'Ztraceno',
                in_array($code, StatusCode::Returned, true) => 'Vráceno odesílateli',
                default => null,
            },
            delivered: $delivered,
            damaged: in_array($code, [StatusCode::Damaged, StatusCode::Lost], true),
        );
    }
}
