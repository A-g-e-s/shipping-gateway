<?php

namespace Ages\ShippingGateway\Ppl\Entity;

use Ages\ShippingGateway\Common\AbstractEntity;
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
    private bool $ascii = false;

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

    /**
     * Texty bez diakritiky – CPL API podle dokumentace přijímá jen latinku bez diakritiky,
     * u mezinárodních zásilek se proto adresy převádějí na ASCII.
     */
    public function withAscii(): static
    {
        $entity = clone $this;
        $entity->ascii = true;
        return $entity;
    }

    public function toArray(): array
    {
        $e = [
            'name' => $this->sanitizeText($this->name),
            'street' => $this->sanitizeText($this->street),
            'city' => $this->sanitizeText($this->city),
            'zipCode' => $this->zipCode,
            'country' => Strings::upper($this->countryIsoCode),
        ];
        if ($this->contactName !== null) {
            $e['contact'] = $this->sanitizeText($this->contactName);
        }
        if ($this->contactPhone !== null) {
            $e['phone'] = $this->contactPhone;
        }
        if ($this->houseNumberInfo !== null) {
            $e['name2'] = $this->sanitizeText($this->houseNumberInfo);
        }
        if ($this->contactEmail !== null) {
            $e['email'] = $this->contactEmail;
        }
        return $e;
    }

    private function sanitizeText(string $value): string
    {
        $sanitized = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;
        $sanitized = preg_replace('/\s+/', ' ', $sanitized) ?? $sanitized;

        $sanitized = trim($sanitized);

        return $this->ascii ? Strings::toAscii($sanitized) : $sanitized;
    }
}
