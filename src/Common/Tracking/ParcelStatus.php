<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Tracking;

use Ages\ShippingGateway\Common\ParcelStatusInterface;
use Nette\Utils\ArrayHash;

/**
 * Jeden stav zásilky, společný pro všechny dopravce. Doručení / poškození a vlastní popis (customInfo)
 * určuje převodník dopravce (…\Tracking\*TrackingMapper) podle jeho číselníku.
 */
final class ParcelStatus implements ParcelStatusInterface
{
    public function __construct(
        public readonly string              $statusCode,
        public readonly string              $statusDescription,
        public readonly ?\DateTimeImmutable $statusDate = null,
        public readonly ?string             $depotCity = null,
        public readonly ?string             $depotCode = null,
        public readonly ?string             $statusInfo = null,
        public readonly ?string             $customInfo = null,
        public readonly bool                $delivered = false,
        public readonly bool                $damaged = false,
    ) {
    }

    /**
     * @return ArrayHash<mixed>
     */
    public function toArrayHash(): ArrayHash
    {
        return ArrayHash::from([
            'depotCity' => $this->depotCity,
            'depotCode' => $this->depotCode,
            'statusCode' => $this->statusCode,
            'statusDate' => $this->statusDate,
            'statusDescription' => $this->statusDescription,
            'statusInfo' => $this->statusInfo,
            'customInfo' => $this->customInfo,
            'delivered' => $this->delivered,
            'damaged' => $this->damaged,
        ]);
    }
}
