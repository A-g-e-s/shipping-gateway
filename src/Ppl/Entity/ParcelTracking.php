<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Ppl\Entity;

use Ages\ShippingGateway\Common\ParcelTrackingInterface;

class ParcelTracking implements ParcelTrackingInterface
{
     private(set) string $deliveryCountryCode {
        get => $this->deliveryCountryCode;
    }
     private(set) string $deliveryZipCode {
        get => $this->deliveryZipCode;
    }
     private(set) string $parcelNumber {
        get => $this->parcelNumber;
    }
     private(set) string $clientReference {
        get => $this->clientReference;
    }
     private(set) bool $delivered = false {
        get => $this->delivered;
    }
     private(set) ?\DateTimeImmutable $deliveredDate = null {
        get => $this->deliveredDate;
    }
     private(set) bool $damaged = false {
        get => $this->damaged;
    }
     private(set) float $weight {
        get => $this->weight;
    }

    /** @var ParcelStatus[] */
     private(set) array $parcelStatuses = [] {
        get => $this->parcelStatuses;
    }

    final private function __construct()
    {
    }

    public static function of(
        bool $delivered,
        ?\DateTimeImmutable $deliveryDate,
        string|float $weight,
        string $deliveryCountryCode,
        string $deliveryZipCode,
        string $parcelNumber,
        string $clientReference,
    ): self {
        $entity = new static();
        $entity->delivered = $delivered;
        $entity->deliveredDate = $deliveryDate;
        $entity->deliveryCountryCode = $deliveryCountryCode;
        $entity->deliveryZipCode = $deliveryZipCode;
        $entity->parcelNumber = $parcelNumber;
        $entity->clientReference = $clientReference;
        $entity->weight = floatval($weight);
        return $entity;
    }

    public function addStatus(ParcelStatus $status): void
    {
        $statuses = $this->parcelStatuses;
        $statuses[] = $status;
        $this->parcelStatuses = $statuses;
        if ($status->delivered) {
            $this->delivered = true;
            $this->deliveredDate = $status->statusDate;
        }
        if ($status->damaged) {
            $this->damaged = true;
        }
    }
}
