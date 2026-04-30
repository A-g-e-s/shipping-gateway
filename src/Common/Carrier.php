<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common;

use Ages\ShippingGateway\Common\Shipment\ParcelType;

enum Carrier: string
{
    case Gls = 'gls';
    case Ppl = 'ppl';
    case CzechPost = 'czechpost';
    case GebruderWeiss = 'gbw';

    /** @return ParcelType[] */
    public function supportedParcelTypes(): array
    {
        return match ($this) {
            self::Gls, self::Ppl, self::CzechPost => [ParcelType::Package, ParcelType::PackageOversize],
            self::GebruderWeiss => ParcelType::cases(),
        };
    }
}
