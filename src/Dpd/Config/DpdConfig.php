<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Dpd\Config;

use Ages\ShippingGateway\Common\PickupAddress;
use Ages\ShippingGateway\Dpd\Values\LabelFormat;

readonly class DpdConfig
{
    /**
     * @param string $apiKey API klíč účtu GeoAPI (hlavička x-api-key)
     * @param string $customerDsw zákaznické číslo DPD (DSW, 11–12 znaků), pod kterým se zásilky zakládají
     * @param int|null $senderAddressId it4emId svozové adresy z GET /me – odesílatel se pak bere z registrace u DPD
     *                                  a jde objednat svoz; null = odesílatel z pickupAddress
     * @param string $url produkce https://geoapi.dpd.cz, test https://geoapi-test.dpd.cz
     * @param bool $notification Predict – SMS/e-mail příjemci s časem doručení (soukromá zásilka)
     * @param bool $insureValue pojistit zásilku na ShipmentValue (rozdělí se rovnoměrně mezi balíky)
     */
    public function __construct(
        public string        $apiKey,
        public string        $customerDsw,
        public PickupAddress $pickupAddress,
        public ?int          $senderAddressId = null,
        public string        $url = 'https://geoapi.dpd.cz',
        public LabelFormat   $labelFormat = LabelFormat::PdfA6,
        public bool          $notification = true,
        public bool          $insureValue = false,
        public string        $trackingUrl = 'https://tracking.dpd.de/status/cs_CZ/parcel/',
    ) {
    }
}
