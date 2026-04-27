<?php

namespace Ages\ShippingGateway\CzechPost\Entity;

use Ages\ShippingGateway\CzechPost\Entity\Values\ServiceCode;

class ParcelServicesEntity extends AbstractEntity
{
    /**
     * @var ServiceCode[]
     */
    private array $services = [];

    final private function __construct()
    {
    }

    public static function of(ServiceCode ...$services): self
    {
        $entity = new static();
        foreach ($services as $service) {
            $entity->services[] = $service;
        }
        return $entity;
    }

    public function addService(ServiceCode $service): void
    {
        $this->services[] = $service;
    }

    public function toArray(): array
    {
        $s = [];
        foreach ($this->services as $service) {
            $s[] = $service->value;
        }
        return $s;
    }
}
