<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Dpd\Tracking;

use Ages\ShippingGateway\Common\Data;
use Ages\ShippingGateway\Common\Tracking\ParcelStatus;
use Ages\ShippingGateway\Common\Tracking\ParcelTracking;

/**
 * GeoAPI v2 tracking (parcelInfo + parcelEvents) → společné tracking entity, seřazené podle data.
 * GeoAPI tracking nevrací váhu.
 */
final class DpdTrackingMapper
{
    /**
     * Skenovací kódy DPD pro doručení: 13 = doručeno, 23 = vyzvednuto na výdejním místě.
     * GeoAPI číselník stavů nepublikuje, proto se doručení pozná i z popisu.
     */
    private const array DeliveredCodes = ['13', '23'];
    private const array DeliveredWords = ['doručen', 'doruceno', 'delivered', 'zugestellt', 'vyzvednut'];
    private const array DamagedWords = ['poškozen', 'damaged', 'beschädigt'];

    private function __construct()
    {
    }

    /**
     * @param array<mixed> $result SuccessfulTrackingResult
     */
    public static function tracking(string $parcelNumber, array $result): ParcelTracking
    {
        $statuses = array_map(self::status(...), Data::arrays($result, 'parcelEvents'));
        usort($statuses, static fn (ParcelStatus $a, ParcelStatus $b): int => $a->statusDate <=> $b->statusDate);

        $number = Data::get($result, ['parcelInfo', 'parcelNumber']);
        $tracking = new ParcelTracking(
            is_string($number) || is_int($number) ? (string) $number : $parcelNumber,
            Data::string($result, ['parcelInfo', 'receiver', 'address', 'country', 'isoAlpha2']),
        );
        foreach ($statuses as $status) {
            $tracking->addStatus($status);
        }
        return $tracking;
    }

    /**
     * @param array<mixed> $event položka parcelEvents
     */
    public static function status(array $event): ParcelStatus
    {
        $code = Data::get($event, ['status', 'statusCode']);
        $code = is_string($code) || is_int($code) ? (string) $code : '';
        $description = Data::nonEmptyString($event, ['status', 'description']) ?? $code;

        $text = mb_strtolower($description);
        $delivered = in_array($code, self::DeliveredCodes, true) || self::containsAny($text, self::DeliveredWords);

        return new ParcelStatus(
            statusCode: $code,
            statusDescription: $description,
            statusDate: Data::date(Data::get($event, 'createdAt')),
            statusInfo: Data::nonEmptyString($event, 'additionalInfo'),
            customInfo: $delivered ? 'Doručeno' : null,
            delivered: $delivered,
            damaged: self::containsAny($text, self::DamagedWords),
        );
    }

    /**
     * @param string[] $words
     */
    private static function containsAny(string $text, array $words): bool
    {
        return array_any($words, static fn (string $word): bool => str_contains($text, $word));
    }
}
