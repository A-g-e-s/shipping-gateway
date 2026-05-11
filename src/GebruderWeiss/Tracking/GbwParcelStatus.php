<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\GebruderWeiss\Tracking;

use Ages\ShippingGateway\Common\ParcelStatusInterface;
use Nette\Utils\ArrayHash;

class GbwParcelStatus implements ParcelStatusInterface
{
    private(set) ?string $depotCity {
        get => $this->depotCity;
    }
    private(set) ?string $depotCode {
        get => $this->depotCode;
    }
    private(set) string $statusCode {
        get => $this->statusCode;
    }
    private(set) ?\DateTimeImmutable $statusDate {
        get => $this->statusDate;
    }
    private(set) string $statusDescription {
        get => $this->statusDescription;
    }
    private(set) ?string $statusInfo {
        get => $this->statusInfo;
    }
    private(set) ?string $customInfo {
        get => $this->customInfo;
    }
    private(set) bool $delivered {
        get => $this->delivered;
    }
    private(set) bool $damaged {
        get => $this->damaged;
    }

    final private function __construct() {}

    /**
     * @param array<string, mixed> $event
     */
    public static function of(array $event): self
    {
        $entity = new static();

        $location = $event['location'] ?? null;
        $entity->depotCity = is_array($location) ? ($location['locationInformation'] ?? null) : null;
        $entity->depotCode = is_array($location) ? ($location['countryCode'] ?? null) : null;

        $myGwCode = is_string($event['myGwStatusCode'] ?? null) ? $event['myGwStatusCode'] : '';
        $entity->statusCode = $myGwCode !== '' ? $myGwCode : (string) ($event['eventCode'] ?? '');

        $entity->statusDate = self::parseDateTime((string) ($event['eventDateTime'] ?? ''));

        $text = '';
        $descriptionTexts = [];
        $description = $event['eventDescription'] ?? null;
        if (is_array($description)) {
            foreach (['translationResolved', 'translationOriginal'] as $translationKey) {
                $translation = $description[$translationKey] ?? null;
                if (is_array($translation) && isset($translation['text']) && is_string($translation['text'])) {
                    $descriptionTexts[] = $translation['text'];
                    if ($text === '') {
                        $text = $translation['text'];
                    }
                }
            }
        }
        $entity->statusDescription = $text !== '' ? $text : $entity->statusCode;

        $signee = $event['eventSignee'] ?? null;
        $entity->statusInfo = is_string($signee) && $signee !== '' ? $signee : null;

        $eventReasonCode = self::extractFirstString($event['eventReasonCode'] ?? null);

        $entity->delivered = self::isDelivered(
            $descriptionTexts,
            $entity->statusDescription,
            $entity->statusCode,
            (string) ($event['eventCode'] ?? ''),
        );
        $entity->damaged = $myGwCode === 'CRITICAL';
        $entity->customInfo = self::buildCustomInfo($myGwCode, $eventReasonCode);

        return $entity;
    }

    /**
     * @param string[] $descriptionTexts
     */
    private static function isDelivered(
        array $descriptionTexts,
        string $statusDescription,
        string $statusCode,
        string $eventCode,
    ): bool
    {
        $texts = $descriptionTexts;
        $texts[] = $statusDescription;

        foreach ($texts as $text) {
            $lowerDescription = trim(mb_strtolower($text));
            if ($lowerDescription === 'delivered' || $lowerDescription === 'zugestellt') {
                return true;
            }

            $asciiDescription = self::toAsciiLower($lowerDescription);
            if (
                $asciiDescription === 'doruceno'
                || $asciiDescription === 'delivered'
                || $asciiDescription === 'zugestellt'
            ) {
                return true;
            }
        }

        $codes = [
            strtoupper(trim($statusCode)),
            strtoupper(trim($eventCode)),
        ];

        return in_array('*DSC*810', $codes, true);
    }

    private static function buildCustomInfo(?string ...$parts): ?string
    {
        $parts = array_values(array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== ''));
        if ($parts === []) {
            return null;
        }

        return implode(' | ', array_unique($parts));
    }

    /**
     * @param mixed $value
     */
    private static function extractFirstString(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item) && $item !== '') {
                    return $item;
                }
            }
        }

        return null;
    }

    private static function parseDateTime(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private static function toAsciiLower(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($transliterated) && $transliterated !== '') {
            return trim(mb_strtolower($transliterated));
        }

        return $value;
    }

    /**
     * @return ArrayHash<mixed>
     */
    public function toArrayHash(): ArrayHash
    {
        return ArrayHash::from([
            'depotCity' => $this->depotCity,
            'depotCode' => $this->depotCode,
            'statusCode' => $this->statusCode,
            'statusDate' => $this->statusDate,
            'statusDescription' => $this->statusDescription,
            'statusInfo' => $this->statusInfo,
            'customInfo' => $this->customInfo,
            'delivered' => $this->delivered,
            'damaged' => $this->damaged,
        ]);
    }
}
