<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Shipment;

readonly class ShipmentRequest
{
    /**
     * @param Parcel[] $parcels
     */
    public function __construct(
        public string $reference,
        public RecipientAddress $recipient,
        public array $parcels,
        public ShipmentValue $value,
        public ?CashOnDelivery $cod = null,
        public ?string $note = null,
        public ?string $parcelShopCode = null,
        public ?string $parcelShopZip = null,
    ) {
    }
}
