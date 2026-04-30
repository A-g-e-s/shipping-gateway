<?php

namespace Ages\ShippingGateway\Common;

readonly class PickupAddress
{
    public function __construct(
        public string  $name,
        public string  $street,
        public string  $city,
        public string  $zip,
        public string  $country,
        public string  $phone,
        public string  $email,
        public ?string $houseNumber = null,
    ) {
    }

    public function getStreetWithNumber(): string
    {
        return $this->houseNumber !== null
            ? trim($this->street . ' ' . $this->houseNumber)
            : $this->street;
    }
}
