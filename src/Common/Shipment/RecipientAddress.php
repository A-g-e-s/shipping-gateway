<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Shipment;

readonly class RecipientAddress
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $street,
        public string $city,
        public string $zip,
        public string $country,
        public string $phone,
        public string $email,
        public ?string $company = null,
        public ?string $houseNumber = null,
        public RecipientType $type = RecipientType::Person,
    ) {
    }

    public static function fromFullName(
        string $name,
        string $street,
        string $city,
        string $zip,
        string $country,
        string $phone,
        string $email,
        ?string $company = null,
        ?string $houseNumber = null,
        RecipientType $type = RecipientType::Person,
    ): self {
        $parts = explode(' ', trim($name), 2);
        [$firstName, $lastName] = count($parts) > 1
            ? [$parts[0], $parts[1]]
            : ['', $parts[0]];

        return new self(
            firstName: $firstName,
            lastName: $lastName,
            street: $street,
            city: $city,
            zip: $zip,
            country: $country,
            phone: $phone,
            email: $email,
            company: $company,
            houseNumber: $houseNumber,
            type: $type,
        );
    }

    public function fullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    public function streetWithNumber(): string
    {
        return trim($this->street . ' ' . $this->houseNumber);
    }
}
