<?php

namespace Ages\ShippingGateway\Ppl\Entity;

use Nette\Utils\Strings;

class AddressEntity extends AbstractEntity
{
    private string $name;
    private string $street;
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
        $entity->houseNumberInfo = $houseNumberInfo;
        $entity->contactName = $contactName;
        $entity->contactPhone = $contactPhone;
        $entity->contactEmail = $contactEmail;
        return $entity;
    }

    public function toArray(): array
    {
        $e = [
            'name' => $this->name,
            'street' => $this->street,
            'city' => $this->city,
            'zipCode' => $this->zipCode,
            'country' => Strings::upper($this->countryIsoCode),
        ];
        if ($this->contactName !== null) {
            $e['contact'] = $this->contactName;
        }
        if ($this->contactPhone !== null) {
            $e['phone'] = $this->contactPhone;
        }
        if ($this->houseNumberInfo !== null) {
            $e['name2'] = $this->houseNumberInfo;
        }
        if ($this->contactEmail !== null) {
            $e['email'] = $this->contactEmail;
        }
        return $e;
    }
}
