<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\CzechPost\Entity;

use Ages\ShippingGateway\Common\ParcelStatusInterface;
use Nette\Utils\ArrayHash;

class ParcelStatus implements ParcelStatusInterface
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
    private(set) ?string $statusInfo = null {
        get => $this->statusInfo;
    }
    private(set) ?string $customInfo = null {
        get => $this->customInfo;
    }
    private(set) bool $delivered {
        get => $this->delivered;
    }
    private(set) bool $damaged = false {
        get => $this->damaged;
    }

    final private function __construct()
    {
    }

    public static function of(
        string $depotCity,
        string $depotCode,
        string $statusCode,
        string $statusDate,
        string $statusDescription,
    ): self
    {
        $entity = new static();
        $entity->depotCity = $depotCity !== '' ? $depotCity : null;
        $entity->depotCode = $depotCode !== '' ? $depotCode : null;
        $entity->statusCode = $statusCode;
        $entity->statusDate = self::parseDateTime($statusDate);
        $entity->statusDescription = $statusDescription;
        $entity->delivered = $statusCode === '91';
        return $entity;
    }

    private static function parseDateTime(string $dateString): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($dateString)->setTimezone(new \DateTimeZone('Europe/Prague'));
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
            'delivered' => $this->delivered,
            'damaged' => $this->damaged,
        ]);
    }
}
