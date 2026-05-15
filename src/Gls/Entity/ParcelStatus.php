<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Gls\Entity;

use Ages\ShippingGateway\Common\ParcelStatusInterface;
use Ages\ShippingGateway\Gls\Entity\Values\StatusCode;
use Nette\Utils\ArrayHash;

class ParcelStatus implements ParcelStatusInterface
{
    private(set) string $depotCity {
        get => $this->depotCity;
    }
    private(set) string $depotCode {
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

    final private function __construct()
    {
    }

    public static function of(
        string $depotCity,
        string $depotCode,
        string $statusCode,
        string $statusDate,
        string $statusDescription,
        string $statusInfo,
    ): self
    {
        $entity = new static();
        $entity->depotCity = $depotCity;
        $entity->depotCode = $depotCode;
        $entity->statusCode = $statusCode;
        $entity->statusDate = self::parseDateTime($statusDate);
        $entity->statusDescription = $statusDescription;
        $entity->statusInfo = $statusInfo !== '' ? $statusInfo : null;
        $myCode = StatusCode::getStatusCode($entity->statusCode);
        if ($myCode !== false) {
            $entity->customInfo = $myCode->value;
            $entity->delivered = StatusCode::isDelivered($myCode);
            $entity->damaged = StatusCode::isDamaged($myCode);
        } else {
            $entity->customInfo = null;
            $entity->delivered = false;
            $entity->damaged = false;
        }
        return $entity;
    }

    private static function parseDateTime(string $dateString): ?\DateTimeImmutable
    {
        try {
            if (preg_match('/\/Date\((\d+)([+-]\d{4})?\)\//', $dateString, $matches) === 1) {
                $date = new \DateTimeImmutable('@' . ((int) $matches[1] / 1000));
                if (isset($matches[2]) && $matches[2] !== '') {
                    return $date->setTimezone(new \DateTimeZone($matches[2]));
                }
                return $date;
            }

            return new \DateTimeImmutable($dateString);
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
