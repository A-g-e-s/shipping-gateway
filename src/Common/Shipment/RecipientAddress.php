<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Shipment;

readonly class RecipientAddress
{
    /** Země, kde má PSČ pevný tvar 5 číslic – dopravci ho chtějí bez mezer. */
    private const array NumericZipCountries = ['CZ', 'SK'];

    public string $zip;

    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $street,
        public string $city,
        string $zip,
        public string $country,
        public string $phone,
        public string $email,
        public ?string $company = null,
        public ?string $houseNumber = null,
        public RecipientType $type = RecipientType::Person,
    ) {
        $this->zip = self::normalizeZip($zip, $country);
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

    /**
     * Ořízne a sloučí bílé znaky (zákazník napíše „621  00“), u CZ/SK odstraní mezery úplně.
     */
    public static function normalizeZip(string $zip, string $country): string
    {
        $zip = trim((string) preg_replace('/\s+/u', ' ', $zip));

        if (in_array(strtoupper(trim($country)), self::NumericZipCountries, true)) {
            $zip = str_replace(' ', '', $zip);
        }

        return $zip;
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
