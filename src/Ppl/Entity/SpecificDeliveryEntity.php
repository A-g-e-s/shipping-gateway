<?php

namespace Ages\ShippingGateway\Ppl\Entity;

use Ages\ShippingGateway\Common\AbstractEntity;

class SpecificDeliveryEntity extends AbstractEntity
{
    public ?string $pdsCode;

    final private function __construct()
    {
    }

    public static function of(?string $psdCode): self
    {
        $entity = new static();
        $entity->pdsCode = $psdCode;
        return $entity;
    }

    public function toArray(): array
    {
        return ['parcelShopCode' => $this->pdsCode ?? ''];
    }
}
