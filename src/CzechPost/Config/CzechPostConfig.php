<?php

namespace Ages\ShippingGateway\CzechPost\Config;

class CzechPostConfig
{
    public function __construct(
        public readonly string $apiToken,
        public readonly string $secretKey,
        public readonly int $idContract,
        public readonly string $customerId,
        public readonly string $postCode,
        public readonly int $locationNumber,
        public readonly string $certificatePath,
        public readonly string $url = 'https://b2b.postaonline.cz:444/restservices/ZSKService/v1/',
        public readonly int $packageLimit = 5,
    ) {
    }
}
