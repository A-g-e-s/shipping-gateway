<?php

namespace Ages\ShippingGateway\Gls\Entity;

use Ages\ShippingGateway\Common\AbstractEntity;

class AddressEntity extends AbstractEntity
{
    private string $name;
    private string $street;
    private ?string $houseNumber = null;
    private ?string $houseNumberInfo = null;
    private string $city;
    private string $zipCode;
    private string $countryIsoCode;
    private ?string $contactName = null;
    private ?string $contactPhone = null;
    private ?string $contactEmail = null;

    final private function __construct()
    {
    }

    public static function of(
        string $name,
        string $street,
        string $city,
        string $zipCode,
        string $countryIsoCode,
        ?string $houseNumber = null,
        ?string $houseNumberInfo = null,
        ?string $contactName = null,
        ?string $contactPhone = null,
        ?string $contactEmail = null,
    ): self {
        $entity = new static();
        $entity->name = $name;
        $entity->street = $street;
        $entity->city = $city;
        $entity->zipCode = $zipCode;
        $entity->countryIsoCode = $countryIsoCode;
        $entity->houseNumber = $houseNumber;
        $entity->houseNumberInfo = $houseNumberInfo;
        $entity->contactName = $contactName;
        $entity->contactPhone = $contactPhone;
        $entity->contactEmail = $contactEmail;
        return $entity;
    }

    public function toArray(): array
    {
        $e = [
            'Name' => $this->name,
            'Street' => $this->street,
            'City' => $this->city,
            'ZipCode' => $this->zipCode,
            'CountryIsoCode' => $this->countryIsoCode,
        ];
        if ($this->houseNumber !== null) {
            $e['HouseNumber'] = $this->houseNumber;
        }
        if ($this->houseNumberInfo !== null) {
            $e['HouseNumberInfo'] = $this->houseNumberInfo;
        }
        if ($this->contactName !== null) {
            $e['ContactName'] = $this->contactName;
        }
        if ($this->contactPhone !== null) {
            $e['ContactPhone'] = $this->contactPhone;
        }
        if ($this->contactEmail !== null) {
            $e['ContactEmail'] = $this->contactEmail;
        }
        return $e;
    }
}
