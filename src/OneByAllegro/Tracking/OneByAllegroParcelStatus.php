<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\OneByAllegro\Tracking;

use Ages\ShippingGateway\Common\ParcelStatusInterface;
use Ages\ShippingGateway\OneByAllegro\Values\StatusCode;
use Nette\Utils\ArrayHash;

class OneByAllegroParcelStatus implements ParcelStatusInterface
{
    private(set) ?string $depotCity {
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
    private(set) ?string $statusInfo = null {
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
     * @param array<mixed> $status StatusResponse z /parcels/tracking
     */
    public static function of(array $status): self
    {
        $entity = new static();

        $code = $status['code'] ?? '';
        $entity->statusCode = is_string($code) ? $code : '';

        $date = $status['date'] ?? null;
        $entity->statusDate = is_string($date) ? self::parseDateTime($date) : null;

        $description = $status['description'] ?? null;
        $entity->statusDescription = is_string($description) && $description !== '' ? $description : $entity->statusCode;

        $place = $status['place'] ?? null;
        $entity->depotCity = is_string($place) && $place !== '' ? $place : null;

        $entity->delivered = $entity->statusCode === StatusCode::Delivered;
        $entity->damaged = in_array($entity->statusCode, [StatusCode::Damaged, StatusCode::Lost], true);
        $entity->customInfo = match (true) {
            $entity->delivered => 'Doručeno',
            $entity->statusCode === StatusCode::Damaged => 'Poškozeno',
            $entity->statusCode === StatusCode::Lost => 'Ztraceno',
            in_array($entity->statusCode, StatusCode::Returned, true) => 'Vráceno odesílateli',
            default => null,
        };

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
