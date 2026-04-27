<?php

namespace Ages\ShippingGateway\CzechPost\Entity;

class MultipartDataEntity extends AbstractEntity
{
    private AddParcelDataEntity $addParcelDataEntity;
    private ParcelServicesEntity $addParcelDataServices;

    final private function __construct()
    {
    }

    public static function of(
        AddParcelDataEntity $addParcelDataEntity,
        ParcelServicesEntity $addParcelDataServices,
    ): self {
        $entity = new static();
        $entity->addParcelDataEntity = $addParcelDataEntity;
        $entity->addParcelDataServices = $addParcelDataServices;
        return $entity;
    }

    public function toArray(): array
    {
        return [
            'addParcelData' => $this->addParcelDataEntity->toArray(),
            'addParcelDataServices' => $this->addParcelDataServices->toArray(),
        ];
    }
}
