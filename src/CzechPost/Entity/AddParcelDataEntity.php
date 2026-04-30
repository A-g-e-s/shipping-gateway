<?php

namespace Ages\ShippingGateway\CzechPost\Entity;

use Ages\ShippingGateway\Common\AbstractEntity;
use Ages\ShippingGateway\CzechPost\Entity\Values\PrefixParcelCode;

class AddParcelDataEntity extends AbstractEntity
{
    private string $recordID {
        get {
            return $this->recordID;
        }
    }
    private ?string $parcelCode;
    private PrefixParcelCode $prefixParcelCode;
    private float $weight {
        get {
            return $this->weight;
        }
    }
    private int $sequenceParcel;
    private int $quantityParcel;

    final private function __construct()
    {
    }

    public static function of(
        string $recordID,
        string|PrefixParcelCode $prefixParcelCode,
        float|int $weight,
        int $sequenceParcel,
        int $quantityParcel,
        ?string $parcelCode = null,
    ): self {
        $entity = new static();
        $entity->recordID = $recordID;
        $entity->prefixParcelCode = ($prefixParcelCode instanceof PrefixParcelCode) ? $prefixParcelCode : PrefixParcelCode::from($prefixParcelCode);
        $entity->weight = round(floatval($weight), 2, PHP_ROUND_HALF_UP);
        $entity->sequenceParcel = $sequenceParcel;
        $entity->quantityParcel = $quantityParcel;
        $entity->parcelCode = $parcelCode;
        return $entity;
    }

    public function toArray(): array
    {
        $e = [
            'recordID' => $this->recordID,
            'prefixParcelCode' => $this->prefixParcelCode->value,
            'weight' => strval($this->weight),
            'sequenceParcel' => $this->sequenceParcel,
            'quantityParcel' => $this->quantityParcel,
        ];
        if ($this->parcelCode !== null) {
            $e['parcelCode'] = $this->parcelCode;
        }
        return $e;
    }
}
