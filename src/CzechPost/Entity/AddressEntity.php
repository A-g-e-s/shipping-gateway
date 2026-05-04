<?php

namespace Ages\ShippingGateway\CzechPost\Entity;

use Ages\ShippingGateway\Common\AbstractEntity;

class AddressEntity extends AbstractEntity
{
    private string $street;
    private ?string $houseNumber = null;
    private ?string $sequenceNumber = null;
    private ?string $cityPart = null;
    private string $city;
    private string $zipCode;
    private string $isoCountry;
    private ?string $subIsoCountry = null;
    private ?int $addressCode = null;

    final private function __construct()
    {
    }

    public static function of(
        string $street,
        string $city,
        string $zipCode,
        string $isoCountry,
        ?string $houseNumber = null,
        ?string $subIsoCountry = null,
        ?string $sequenceNumber = null,
        ?string $cityPart = null,
        ?int $addressCode = null,
    ): self {
        $entity = new static();
        $entity->street = $street;
        $entity->houseNumber = $houseNumber;
        $entity->sequenceNumber = $sequenceNumber;
        $entity->cityPart = $cityPart;
        $entity->city = $city;
        $entity->zipCode = $zipCode;
        $entity->isoCountry = $isoCountry;
        $entity->subIsoCountry = $subIsoCountry;
        $entity->addressCode = $addressCode;
        return $entity;
    }

    public function toArray(): array
    {
        $e = [
            'street' => $this->street,
            'city' => $this->city,
            'zipCode' => $this->zipCode,
            'isoCountry' => $this->isoCountry,
        ];
        if ($this->houseNumber !== null) { $e['houseNumber'] = $this->houseNumber; }
        if ($this->sequenceNumber !== null) { $e['sequenceNumber'] = $this->sequenceNumber; }
        if ($this->cityPart !== null) { $e['cityPart'] = $this->cityPart; }
        if ($this->subIsoCountry !== null) { $e['subIsoCountry'] = $this->subIsoCountry; }
        if ($this->addressCode !== null) { $e['addressCode'] = $this->addressCode; }
        return $e;
    }
}
