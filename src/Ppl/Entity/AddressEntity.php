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
            'name' => self::sanitizeText($this->name),
            'street' => self::sanitizeText($this->street),
            'city' => self::sanitizeText($this->city),
            'zipCode' => $this->zipCode,
            'country' => Strings::upper($this->countryIsoCode),
        ];
        if ($this->contactName !== null) {
            $e['contact'] = self::sanitizeText($this->contactName);
        }
        if ($this->contactPhone !== null) {
            $e['phone'] = $this->contactPhone;
        }
        if ($this->houseNumberInfo !== null) {
            $e['name2'] = self::sanitizeText($this->houseNumberInfo);
        }
        if ($this->contactEmail !== null) {
            $e['email'] = $this->contactEmail;
        }
        return $e;
    }

    private static function sanitizeText(string $value): string
    {
        $sanitized = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;
        $sanitized = preg_replace('/\s+/', ' ', $sanitized) ?? $sanitized;

        return trim($sanitized);
    }
}
