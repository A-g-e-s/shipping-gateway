<?php

namespace Ages\ShippingGateway\CzechPost\Entity;

use Ages\ShippingGateway\Common\ParcelStatusInterface;
use Nette\Utils\ArrayHash;
use Tracy\Debugger;

class ParcelStatus implements ParcelStatusInterface
{
    private ?string $depotCity;
    private ?string $depotCode;
    private string $statusCode;
    private ?\DateTimeImmutable $statusDate;
    private string $statusDescription;
    private bool $delivered;
    private bool $damaged = false;
    private ?string $customInfo = null;

    final private function __construct()
    {
    }

    public static function of(
        string $depotCity,
        string $depotCode,
        string $statusCode,
        string $statusDate,
        string $statusDescription,
    ): self {
        $entity = new static();
        $entity->depotCity = $depotCity !== '' ? $depotCity : null;
        $entity->depotCode = $depotCode !== '' ? $depotCode : null;
        $entity->statusCode = $statusCode;
        $entity->statusDate = self::getDateTime($statusDate);
        $entity->statusDescription = $statusDescription;
        $entity->delivered = $statusCode === '91';
        return $entity;
    }

    private static function getDateTime(string $dateString): ?DateTimeImmutable
    {
        try {
            $date = new DateTimeImmutable($dateString);
            return $date->setTimezone(new \DateTimeZone('Europe/Prague'));
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
            'delivered' => $this->delivered,
            'damaged' => $this->damaged,
        ]);
    }

    public function getDelivered(): bool { return $this->delivered; }
    public function getDamaged(): bool { return $this->damaged; }
    public function getCustomInfo(): ?string { return $this->customInfo; }
    public function getStatusInfo(): ?string { return null; }
    public function getStatusDescription(): string { return $this->statusDescription; }
    public function getStatusDate(): ?\DateTimeImmutable { return $this->statusDate; }
    public function getStatusCode(): string { return $this->statusCode; }
    public function getDepotCode(): ?string { return $this->depotCode; }
    public function getDepotCity(): ?string { return $this->depotCity; }
}
