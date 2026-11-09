<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\OneByAllegro\Config;

use Ages\ShippingGateway\Common\PickupAddress;
use Ages\ShippingGateway\OneByAllegro\Values\LabelFormat;
use Ages\ShippingGateway\OneByAllegro\Values\Service;

readonly class OneByAllegroConfig
{
    /**
     * @param string $username přihlašovací jméno z podpisu smlouvy (One by Allegro)
     * @param string $password heslo z podpisu smlouvy
     * @param string $url produkce https://api.onedelivery.tech, sandbox https://api.onedelivery.tech.allegrosandbox.pl
     * @param string $contentDescription popis obsahu zásilky (max 50 znaků, jen písmena, číslice, mezery, čárka, tečka)
     * @param float $defaultLength rozměry balíku v cm, když Parcel nemá dimensions (API je vyžaduje)
     */
    public function __construct(
        public string        $username,
        public string        $password,
        public PickupAddress $pickupAddress,
        public string        $url = 'https://api.onedelivery.tech',
        public LabelFormat   $labelFormat = LabelFormat::PdfA6,
        public Service       $service = Service::Standard,
        public string        $contentDescription = 'Ecommerce shipment',
        public string        $language = 'cs-CZ',
        public float         $defaultLength = 30.0,
        public float         $defaultWidth = 20.0,
        public float         $defaultHeight = 15.0,
        public string        $trackingUrl = 'https://onedelivery.cz/sledovani-zasilky?numer=',
    ) {
    }
}
