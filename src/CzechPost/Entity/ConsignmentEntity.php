<?php

namespace Ages\ShippingGateway\CzechPost\Entity;

use Ages\ShippingGateway\Common\AbstractEntity;

class ConsignmentEntity extends AbstractEntity
{
    private ParcelParamsEntity $parcelParams;
    private ParcelAddressEntity $parcelAddress;
    private ParcelServicesEntity $parcelServices;

    final private function __construct()
    {
    }

    public static function of(
        ParcelParamsEntity $parcelParams,
        ParcelAddressEntity $parcelAddress,
        ParcelServicesEntity $parcelServices,
    ): self {
        $entity = new static();
        $entity->parcelParams = $parcelParams;
        $entity->parcelAddress = $parcelAddress;
        $entity->parcelServices = $parcelServices;
        return $entity;
    }

    public function toArray(): array
    {
        return [
            'parcelParams' => $this->parcelParams->toArray(),
            'parcelAddress' => $this->parcelAddress->toArray(),
            'parcelServices' => $this->parcelServices->toArray(),
        ];
    }
}
