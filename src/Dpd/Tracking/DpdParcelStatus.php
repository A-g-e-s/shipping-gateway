<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Dpd\Tracking;

use Ages\ShippingGateway\Common\ParcelStatusInterface;
use Nette\Utils\ArrayHash;

class DpdParcelStatus implements ParcelStatusInterface
{
    /**
     * Skenovací kódy DPD pro doručení: 13 = doručeno, 23 = vyzvednuto na výdejním místě.
     * GeoAPI číselník stavů nepublikuje, proto se doručení pozná i z popisu.
     */
    private const array DeliveredCodes = ['13', '23'];
    private const array DeliveredWords = ['doručen', 'doruceno', 'delivered', 'zugestellt', 'vyzvednut'];
    private const array DamagedWords = ['poškozen', 'damaged', 'beschädigt'];

    private(set) ?string $depotCity = null {
        get => $this->depotCity;
    }
    private(set) ?string $depotCode = null {
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
     * @param array<mixed> $event položka parcelEvents z GeoAPI trackingu
     */
    public static function of(array $event): self
    {
        $entity = new static();

        $status = is_array($event['status'] ?? null) ? $event['status'] : [];
        $code = $status['statusCode'] ?? '';
        $entity->statusCode = is_string($code) || is_int($code) ? (string) $code : '';

        $description = $status['description'] ?? null;
        $entity->statusDescription = is_string($description) && $description !== '' ? $description : $entity->statusCode;

        $info = $event['additionalInfo'] ?? null;
        $entity->statusInfo = is_string($info) && $info !== '' ? $info : null;

        $date = $event['createdAt'] ?? null;
        $entity->statusDate = is_string($date) ? self::parseDateTime($date) : null;

        $text = mb_strtolower($entity->statusDescription);
        $entity->delivered = in_array($entity->statusCode, self::DeliveredCodes, true) || self::containsAny($text, self::DeliveredWords);
        $entity->damaged = self::containsAny($text, self::DamagedWords);
        $entity->customInfo = $entity->delivered ? 'Doručeno' : null;

        return $entity;
    }

    /**
     * @param string[] $words
     */
    private static function containsAny(string $text, array $words): bool
    {
        foreach ($words as $word) {
            if (str_contains($text, $word)) {
                return true;
            }
        }
        return false;
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
