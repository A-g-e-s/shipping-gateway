<?php

namespace Ages\ShippingGateway\CzechPost\Config;

readonly class CzechPostConfig
{
    public function __construct(
        public string $apiToken,
        public string $secretKey,
        public int    $idContract,
        public string $customerId,
        public string $postCode,
        public int    $locationNumber,
        public string $certificatePath,
        public string $url = 'https://b2b.postaonline.cz:444/restservices/ZSKService/v1/',
        public int    $packageLimit = 5,
    ) {
    }
}
