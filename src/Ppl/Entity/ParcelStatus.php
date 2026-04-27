<?php

namespace Ages\ShippingGateway\Ppl\Entity;

use Ages\ShippingGateway\Common\ParcelStatusInterface;
use Nette\Utils\ArrayHash;
use Nextras\Dbal\Utils\DateTimeImmutable;
use Tracy\Debugger;

class ParcelStatus implements ParcelStatusInterface
{
    private string $depotCity;
    private string $depotCode;
    private string $statusCode;
    private ?\DateTimeImmutable $statusDate;
    private string $statusDescription;
    private ?string $statusInfo;
    private ?string $customInfo;
    private bool $delivered;
    private bool $damaged;

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
    ): self {
        $entity = new static();
        $entity->depotCity = $depotCity;
        $entity->depotCode = $depotCode;
        $entity->statusCode = $statusCode;
        $entity->statusDate = self::getDateTime($statusDate);
        $entity->statusDescription = $statusDescription;
        $entity->statusInfo = $statusInfo !== '' ? $statusInfo : null;
        if ($entity->statusCode === 'Delivered') {
            $entity->customInfo = 'Doručeno';
            $entity->delivered = true;
            $entity->damaged = false;
        } else {
            $entity->customInfo = null;
            $entity->delivered = false;
            $entity->damaged = false;
        }
        return $entity;
    }

    private static function getDateTime(string $dateString): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($dateString);
        } catch (\Exception $exception) {
            Debugger::log($exception);
            return null;
        }
    }

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

    public function getDelivered(): bool { return $this->delivered; }
    public function getDamaged(): bool { return $this->damaged; }
    public function getCustomInfo(): ?string { return $this->customInfo; }
    public function getStatusInfo(): ?string { return $this->statusInfo; }
    public function getStatusDescription(): string { return $this->statusDescription; }
    public function getStatusDate(): ?\DateTimeImmutable { return $this->statusDate; }
    public function getStatusCode(): string { return $this->statusCode; }
    public function getDepotCode(): string { return $this->depotCode; }
    public function getDepotCity(): string { return $this->depotCity; }
}
