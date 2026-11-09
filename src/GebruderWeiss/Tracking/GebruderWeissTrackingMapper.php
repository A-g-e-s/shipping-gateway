<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\GebruderWeiss\Tracking;

use Ages\ShippingGateway\Common\Data;
use Ages\ShippingGateway\Common\Tracking\ParcelStatus;
use Ages\ShippingGateway\Common\Tracking\ParcelTracking;

/**
 * Odpověď trackntrace (stav objednávky i balíku) → společné tracking entity.
 * Doručeno: popis „delivered / zugestellt / doručeno“ nebo kód *DSC*810. Poškozeno: myGwStatusCode CRITICAL.
 * Země doručení se bere z první události, která má zemi lokace.
 */
final class GebruderWeissTrackingMapper
{
    private const string DeliveredCode = '*DSC*810';
    private const array DeliveredTexts = ['delivered', 'zugestellt', 'doruceno'];
    private const string DamagedCode = 'CRITICAL';

    private function __construct()
    {
    }

    /**
     * Události ze statusHistory; když chybí, statusCurrent; když chybí i ten, seznam událostí v kořeni odpovědi.
     *
     * @param array<mixed> $data
     */
    public static function tracking(string $parcelNumber, array $data): ParcelTracking
    {
        $statuses = self::events(Data::array($data, 'statusHistory'));
        if ($statuses === []) {
            $current = Data::get($data, 'statusCurrent');
            if (is_array($current)) {
                $statuses = [self::status($current)];
            }
        }
        if ($statuses === []) {
            $statuses = self::events($data);
        }

        $country = '';
        foreach ($statuses as $status) {
            if ($status->depotCode !== null && $status->depotCode !== '') {
                $country = $status->depotCode;
                break;
            }
        }

        $tracking = new ParcelTracking($parcelNumber, $country);
        foreach ($statuses as $status) {
            $tracking->addStatus($status);
        }
        return $tracking;
    }

    /**
     * @param array<mixed> $event položka statusHistory / statusCurrent
     */
    public static function status(array $event): ParcelStatus
    {
        $myGwCode = Data::string($event, 'myGwStatusCode');
        $eventCode = Data::string($event, 'eventCode');
        $statusCode = $myGwCode !== '' ? $myGwCode : $eventCode;

        $descriptionTexts = [];
        foreach (['translationResolved', 'translationOriginal'] as $translationKey) {
            $text = Data::get($event, ['eventDescription', $translationKey, 'text']);
            if (is_string($text)) {
                $descriptionTexts[] = $text;
            }
        }
        $firstText = array_find($descriptionTexts, static fn (string $text): bool => $text !== '') ?? '';
        $description = $firstText !== '' ? $firstText : $statusCode;

        return new ParcelStatus(
            statusCode: $statusCode,
            statusDescription: $description,
            statusDate: Data::date(Data::get($event, 'eventDateTime')),
            depotCity: Data::nullableString($event, ['location', 'locationInformation']),
            depotCode: Data::nullableString($event, ['location', 'countryCode']),
            statusInfo: Data::nonEmptyString($event, 'eventSignee'),
            customInfo: self::customInfo($myGwCode, self::firstString(Data::get($event, 'eventReasonCode'))),
            delivered: self::isDelivered([...$descriptionTexts, $description], $statusCode, $eventCode),
            damaged: $myGwCode === self::DamagedCode,
        );
    }

    /**
     * @param array<mixed> $events
     * @return ParcelStatus[]
     */
    private static function events(array $events): array
    {
        $statuses = [];
        foreach (Data::arrays($events, []) as $event) {
            if (isset($event['eventCode'], $event['eventDateTime'])) {
                $statuses[] = self::status($event);
            }
        }
        return $statuses;
    }

    /**
     * @param string[] $texts
     */
    private static function isDelivered(array $texts, string $statusCode, string $eventCode): bool
    {
        foreach ($texts as $text) {
            $lower = trim(mb_strtolower($text));
            if (in_array($lower, self::DeliveredTexts, true) || in_array(self::toAsciiLower($lower), self::DeliveredTexts, true)) {
                return true;
            }
        }

        return in_array(self::DeliveredCode, [strtoupper(trim($statusCode)), strtoupper(trim($eventCode))], true);
    }

    private static function customInfo(?string ...$parts): ?string
    {
        $parts = array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== '');
        return $parts !== [] ? implode(' | ', array_unique($parts)) : null;
    }

    private static function firstString(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (is_array($value)) {
            return array_find($value, static fn (mixed $item): bool => is_string($item) && $item !== '');
        }
        return null;
    }

    private static function toAsciiLower(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return is_string($transliterated) && $transliterated !== '' ? trim(mb_strtolower($transliterated)) : $value;
    }
}
