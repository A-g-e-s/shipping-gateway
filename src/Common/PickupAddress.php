<?php

namespace Ages\ShippingGateway\Common;

class PickupAddress
{
    public function __construct(
        public readonly string $name,
        public readonly string $street,
        public readonly string $city,
        public readonly string $zip,
        public readonly string $country,
        public readonly string $phone,
        public readonly string $email,
        public readonly ?string $houseNumber = null,
    ) {
    }

    public function getStreetWithNumber(): string
    {
        return $this->houseNumber !== null
            ? trim($this->street . ' ' . $this->houseNumber)
            : $this->street;
    }
}
