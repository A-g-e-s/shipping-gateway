<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Shipment;

enum ParcelType
{
    case Package;
    case PackageOversize;
    case PalletEur;
    case PalletOneWay;
    case PalletHalf;
    case PalletCustom;

    public function isPallet(): bool
    {
        return match ($this) {
            self::PalletEur, self::PalletOneWay, self::PalletHalf, self::PalletCustom => true,
            default => false,
        };
    }
}
