<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Shipment;

/**
 * Svoz od dodavatele k nám: dopravce zboží vyzvedne u $shipper a doveze na pickupAddress z konfigurace dopravce.
 * Štítky řeší dopravce, proto se negenerují.
 */
readonly class ImportRequest
{
    private const int NoteLimit = 100;
    public ?string $note;
    public \DateTimeImmutable $collectionDate;

    /**
     * @param Parcel[] $parcels
     */
    public function __construct(
        public string           $reference,
        public RecipientAddress $shipper,
        public array            $parcels,
        \DateTimeInterface      $collectionDate,
        ?string                 $note = null,
    )
    {
        $this->collectionDate = \DateTimeImmutable::createFromInterface($collectionDate)->setTime(0, 0);
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
