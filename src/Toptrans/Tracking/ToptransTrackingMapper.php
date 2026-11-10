<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Toptrans\Tracking;

use Ages\ShippingGateway\Common\Data;
use Ages\ShippingGateway\Common\Tracking\ParcelStatus;
use Ages\ShippingGateway\Common\Tracking\ParcelTracking;

/**
 * order/search → společné tracking entity. API vrací jen aktuální stav (text bez kódu, např. „Na rozvozu“),
 * datum nakládky a datum doručení – historie událostí ani hmotnost nejsou k dispozici.
 */
final class ToptransTrackingMapper
{
    private const string DateFormat = '!d.m.Y';
    private const string LoadedCode = 'loaded';
    private const array DeliveredWords = ['doručen', 'doruceno', 'vydáno', 'vydano', 'delivered'];
    private const array DamagedWords = ['poškoz', 'poskoz', 'damaged'];
    private const string Timezone = 'Europe/Prague';

    private function __construct()
    {
    }

    /**
     * @param array<mixed> $record položka odpovědi order/search
     */
    public static function tracking(string $orderNumber, array $record): ParcelTracking
    {
        $tracking = new ParcelTracking(
            Data::nonEmptyString($record, 'orderNumber') ?? $orderNumber,
            clientReference: Data::nonEmptyString($record, 'label'),
        );

        $loadingDate = self::date(Data::get($record, 'loadingDate'));
        if ($loadingDate !== null) {
            $tracking->addStatus(new ParcelStatus(self::LoadedCode, 'Naloženo', $loadingDate));
        }
        $tracking->addStatus(self::status($record));

        return $tracking;
    }

    /**
     * Aktuální stav – doručeno, když je vyplněné deliveryDate nebo to říká text stavu.
     *
     * @param array<mixed> $record
     */
    public static function status(array $record): ParcelStatus
    {
        $description = Data::nonEmptyString($record, 'status') ?? '';
        $text = mb_strtolower($description);
        $deliveryDate = self::date(Data::get($record, 'deliveryDate'));
        $delivered = $deliveryDate !== null || self::containsAny($text, self::DeliveredWords);

        return new ParcelStatus(
            statusCode: $description,
            statusDescription: $description !== '' ? $description : ($delivered ? 'Doručeno' : ''),
            statusDate: $deliveryDate,
            depotCity: Data::nonEmptyString($record, 'deliveryBranch'),
            statusInfo: Data::nonEmptyString($record, 'deliveryDriver'),
            customInfo: $delivered ? 'Doručeno' : null,
            delivered: $delivered,
            damaged: self::containsAny($text, self::DamagedWords),
        );
    }

    /**
     * Datum ve tvaru dd.mm.rrrr (výstupy API), případně jiný tvar, který zná DateTimeImmutable.
     */
    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $timezone = new \DateTimeZone(self::Timezone);
        $date = \DateTimeImmutable::createFromFormat(self::DateFormat, trim($value), $timezone);
        return $date !== false ? $date : Data::date($value, $timezone);
    }

    /**
     * @param string[] $words
     */
    private static function containsAny(string $text, array $words): bool
    {
        return array_any($words, static fn (string $word): bool => str_contains($text, $word));
    }
}
