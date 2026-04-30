<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\CzechPost\Entity;

use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Nette\Utils\ArrayHash;

class ParcelTracking implements ParcelTrackingInterface
{
    private(set) string $deliveryCountryCode {
        get => $this->deliveryCountryCode;
    }
    private(set) string $parcelNumber {
        get => $this->parcelNumber;
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
        string $deliveryCountryCode,
        string $parcelNumber,
        string|float $weight,
    ): self {
        $entity = new static();
        $entity->deliveryCountryCode = $deliveryCountryCode;
        $entity->parcelNumber = $parcelNumber;
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

    /**
     * @return ArrayHash<mixed>
     */
    public function toArrayHash(): ArrayHash
    {
        $r = [];
        foreach ($this->parcelStatuses as $status) {
            $r[] = $status->toArrayHash();
        }
        return ArrayHash::from([
            'deliveryCountryCode' => $this->deliveryCountryCode,
            'parcelNumber'        => $this->parcelNumber,
            'weight'              => $this->weight,
            'delivered'           => $this->delivered,
            'damaged'             => $this->damaged,
            'parcelStatus'        => $r,
        ]);
    }
}
