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
        $description = $event['eventDescription'] ?? null;
        if (is_array($description)) {
            $resolved = $description['translationResolved'] ?? $description['translationOriginal'] ?? null;
            if (is_array($resolved) && isset($resolved['text'])) {
                $text = (string) $resolved['text'];
            }
        }
        $entity->statusDescription = $text !== '' ? $text : $entity->statusCode;

        $signee = $event['eventSignee'] ?? null;
        $entity->statusInfo = is_string($signee) && $signee !== '' ? $signee : null;

        $eventReasonCode = self::extractFirstString($event['eventReasonCode'] ?? null);

        $entity->delivered = self::isDelivered($entity->statusDescription);
        $entity->damaged = $myGwCode === 'CRITICAL';
        $entity->customInfo = self::buildCustomInfo($myGwCode, $eventReasonCode);

        return $entity;
    }

    private static function isDelivered(string $statusDescription): bool
    {
        $lowerDescription = trim(mb_strtolower($statusDescription));
        if ($lowerDescription === 'delivered' || $lowerDescription === 'zugestellt') {
            return true;
        }

        $asciiDescription = self::toAsciiLower($lowerDescription);
        return $asciiDescription === 'doruceno'
            || $asciiDescription === 'delivered'
            || $asciiDescription === 'zugestellt';
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
