<?php

namespace Ages\ShippingGateway\CzechPost\Entity;

use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Nette\Utils\ArrayHash;

class ParcelTracking implements ParcelTrackingInterface
{
    private string $deliveryCountryCode;
    private string $parcelNumber;
    private bool $delivered = false;
    private ?\DateTimeImmutable $deliveredDate = null;
    private bool $damaged = false;
    /**
     * @var ParcelStatus[]
     */
    private array $parcelStatuses = [];
    private float $weight;

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
        $this->parcelStatuses[] = $status;
        if ($status->getDelivered() === true) {
            $this->delivered = true;
            $this->deliveredDate = $status->getStatusDate();
        }
        if ($status->getDamaged() === true) {
            $this->damaged = true;
        }
    }

    public function toArrayHash(): ArrayHash
    {
        $r = [];
        foreach ($this->parcelStatuses as $status) {
            $r[] = $status->toArrayHash();
        }
        return ArrayHash::from([
            'deliveryCountryCode' => $this->deliveryCountryCode,
            'parcelNumber' => $this->parcelNumber,
            'weight' => $this->weight,
            'delivered' => $this->delivered,
            'damaged' => $this->damaged,
            'parcelStatus' => $r,
        ]);
    }

    public function getDeliveryCountryCode(): string { return $this->deliveryCountryCode; }
    public function getParcelNumber(): string { return $this->parcelNumber; }
    public function getDelivered(): bool { return $this->delivered; }
    public function getDeliveredDate(): ?\DateTimeImmutable { return $this->deliveredDate; }
    public function getDamaged(): bool { return $this->damaged; }
    public function getWeight(): float { return $this->weight; }

    /**
     * @return ParcelStatus[]
     */
    public function getParcelStatuses(): array { return $this->parcelStatuses; }
}
