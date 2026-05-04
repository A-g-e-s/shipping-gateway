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

        $entity->delivered = $myGwCode === 'COMPLETED';
        $entity->damaged = $myGwCode === 'CRITICAL';
        $entity->customInfo = $myGwCode !== '' ? $myGwCode : null;

        return $entity;
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
