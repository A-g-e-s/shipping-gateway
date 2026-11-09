<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Dpd\Tracking;

use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Nette\Utils\ArrayHash;

class DpdParcelTracking implements ParcelTrackingInterface
{
    private(set) string $parcelNumber {
        get => $this->parcelNumber;
    }
    private(set) string $deliveryCountryCode = '' {
        get => $this->deliveryCountryCode;
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
    /** GeoAPI tracking váhu nevrací. */
    private(set) float $weight = 0.0 {
        get => $this->weight;
    }

    /** @var DpdParcelStatus[] */
    private(set) array $parcelStatuses = [] {
        get => $this->parcelStatuses;
    }

    final private function __construct() {}

    public static function of(string $parcelNumber, string $deliveryCountryCode = ''): self
    {
        $entity = new static();
        $entity->parcelNumber = $parcelNumber;
        $entity->deliveryCountryCode = $deliveryCountryCode;
        return $entity;
    }

    public function addStatus(DpdParcelStatus $status): void
    {
        $statuses = $this->parcelStatuses;
        $statuses[] = $status;
        $this->parcelStatuses = $statuses;

        if ($status->delivered) {
            $this->delivered = true;
            if (
                $this->deliveredDate === null
                || ($status->statusDate !== null && $status->statusDate > $this->deliveredDate)
            ) {
                $this->deliveredDate = $status->statusDate;
            }
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
        $statuses = [];
        foreach ($this->parcelStatuses as $status) {
            $statuses[] = $status->toArrayHash();
        }
        return ArrayHash::from([
            'parcelNumber' => $this->parcelNumber,
            'deliveryCountryCode' => $this->deliveryCountryCode,
            'delivered' => $this->delivered,
            'deliveredDate' => $this->deliveredDate,
            'damaged' => $this->damaged,
            'weight' => $this->weight,
            'parcelStatuses' => $statuses,
        ]);
    }
}
