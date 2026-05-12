<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Shipment;

readonly class ShipmentRequest
{
    private const int NoteLimit = 100;
    public ?string $note;

    /**
     * @param Parcel[] $parcels
     */
    public function __construct(
        public string           $reference,
        public RecipientAddress $recipient,
        public array            $parcels,
        public ShipmentValue    $value,
        public ?CashOnDelivery  $cod = null,
        ?string                 $note = null,
        public ?string          $parcelShopCode = null,
        public ?string          $parcelShopZip = null,
    )
    {
        $this->note = $this->normalizeNote($note);
    }

    private function normalizeNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $note = trim($note);
        if ($note === '') {
            return null;
        }

        return mb_substr($note, 0, self::NoteLimit);
    }
}
