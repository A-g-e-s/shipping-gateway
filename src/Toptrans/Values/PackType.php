<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Toptrans\Values;

use Ages\ShippingGateway\Common\Shipment\ParcelType;

/**
 * Druh obalu (číselník register/pack) – výběr pro obal kusu.
 */
enum PackType: int
{
    case Piece = 1;
    case Carton = 2;
    case EurPallet = 3;
    case Bag = 7;
    case Pallet = 13;
    case HalfPallet = 25;
    case Box = 33;
    case Parcel = 55;

    /**
     * Obal pro typ zásilky; balíky dostanou $package (podle zboží – karton, pytel…).
     */
    public static function forParcelType(ParcelType $type, self $package): self
    {
        return match ($type) {
            ParcelType::Package, ParcelType::PackageOversize => $package,
            ParcelType::PalletEur => self::EurPallet,
            ParcelType::PalletHalf => self::HalfPallet,
            ParcelType::PalletOneWay, ParcelType::PalletCustom => self::Pallet,
        };
    }
}
