<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Tracking;

use Ages\ShippingGateway\Common\ParcelTrackingInterface;
use Nette\Utils\ArrayHash;

/**
 * Sledování zásilky, společné pro všechny dopravce. Stavy se přidávají v pořadí, v jakém se mají zobrazit;
 * doručený stav nastaví delivered + deliveredDate, poškozený damaged.
 */
final class ParcelTracking implements ParcelTrackingInterface
{
    public private(set) bool $delivered = false;
    public private(set) ?\DateTimeImmutable $deliveredDate = null;
    public private(set) bool $damaged = false;

    /** @var ParcelStatus[] */
    public private(set) array $parcelStatuses = [];

    /**
     * @param string|null $deliveryZipCode PSČ příjemce, jen když ho dopravce v trackingu vrací (GLS, PPL)
     * @param string|null $clientReference reference zásilky u dopravce (GLS, PPL)
     */
    public function __construct(
        public readonly string        $parcelNumber,
        public readonly string        $deliveryCountryCode = '',
        public readonly float         $weight = 0.0,
        public readonly ?string       $deliveryZipCode = null,
        public readonly ?string       $clientReference = null,
        private readonly DeliveredDate $deliveredDatePolicy = DeliveredDate::Latest,
    ) {
    }

    /**
     * Doručení podle stavu zásilky (ne podle událostí) – stavy přidané potom datum ještě přepíšou.
     */
    public function markDelivered(?\DateTimeImmutable $date): void
    {
        $this->delivered = true;
        $this->deliveredDate = $date;
    }

    public function addStatus(ParcelStatus $status): void
    {
        $this->parcelStatuses[] = $status;

        if ($status->delivered) {
            $this->deliveredDate = match ($this->deliveredDatePolicy) {
                DeliveredDate::LastAdded => $status->statusDate,
                DeliveredDate::Latest => $this->deliveredDate === null
                    || ($status->statusDate !== null && $status->statusDate > $this->deliveredDate)
                    ? $status->statusDate
                    : $this->deliveredDate,
            };
            $this->delivered = true;
        }
        if ($status->damaged) {
            $this->damaged = true;
        }
    }

    /**
     * @return ArrayHash<mixed>
     */
    public function toArrayHash(): ArrayHash
    {
        $statuses = array_map(static fn (ParcelStatus $status): ArrayHash => $status->toArrayHash(), $this->parcelStatuses);
        return ArrayHash::from([
            'parcelNumber' => $this->parcelNumber,
            'deliveryCountryCode' => $this->deliveryCountryCode,
            'deliveryZipCode' => $this->deliveryZipCode,
            'clientReference' => $this->clientReference,
            'weight' => $this->weight,
            'delivered' => $this->delivered,
            'deliveredDate' => $this->deliveredDate,
            'damaged' => $this->damaged,
            'parcelStatuses' => $statuses,
            // starší název klíče (GLS, Česká pošta)
            'parcelStatus' => $statuses,
        ]);
    }
}
