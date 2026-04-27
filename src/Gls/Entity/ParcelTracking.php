<?php

namespace Ages\ShippingGateway\Gls\Entity;

use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Nette\Utils\ArrayHash;

class ParcelTracking implements ParcelTrackingInterface
{
    private string $deliveryCountryCode;
    private string $deliveryZipCode;
    private string $parcelNumber;
    private string $clientReference;
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
        string $deliveryZipCode,
        string $parcelNumber,
        string $clientReference,
        string|float $weight,
    ): self {
        $entity = new static();
        $entity->deliveryCountryCode = $deliveryCountryCode;
        $entity->deliveryZipCode = $deliveryZipCode;
        $entity->parcelNumber = $parcelNumber;
        $entity->clientReference = $clientReference;
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
            'deliveryZipCode' => $this->deliveryZipCode,
            'parcelNumber' => $this->parcelNumber,
            'clientReference' => $this->clientReference,
            'weight' => $this->weight,
            'delivered' => $this->delivered,
            'damaged' => $this->damaged,
            'parcelStatus' => $r,
        ]);
    }

    public function getDeliveryCountryCode(): string { return $this->deliveryCountryCode; }
    public function getDeliveryZipCode(): string { return $this->deliveryZipCode; }
    public function getParcelNumber(): string { return $this->parcelNumber; }
    public function getClientReference(): string { return $this->clientReference; }
    public function getDelivered(): bool { return $this->delivered; }
    public function getDeliveredDate(): ?\DateTimeImmutable { return $this->deliveredDate; }
    public function getDamaged(): bool { return $this->damaged; }
    public function getWeight(): float { return $this->weight; }

    /**
     * @return ParcelStatus[]
     */
    public function getParcelStatuses(): array { return $this->parcelStatuses; }
}
