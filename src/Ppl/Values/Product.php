<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Ppl\Values;

/**
 * Produkty PPL (productType). Tuzemské vyžadují stejnou zemi odesílatele a příjemce,
 * mezinárodní naopak jinou. Aktivní produkty smlouvy vrací GET codelist/product.
 */
enum Product: string
{
    /** PPL Parcel CZ Private – na adresu */
    case Private = 'PRIV';
    case PrivateCod = 'PRID';
    /** PPL Parcel CZ Smart – na výdejní místo / box */
    case Smart = 'SMAR';
    case SmartCod = 'SMAD';
    /** PPL Parcel Connect – na adresu v zahraničí */
    case Connect = 'CONN';
    case ConnectCod = 'COND';
    /** PPL Parcel Smart Europe – na výdejní místo / box v zahraničí */
    case SmartEurope = 'SMEU';
    case SmartEuropeCod = 'SMED';

    public static function resolve(bool $international, bool $pickupPoint, bool $cod): self
    {
        return match (true) {
            !$international && !$pickupPoint => $cod ? self::PrivateCod : self::Private,
            !$international && $pickupPoint  => $cod ? self::SmartCod : self::Smart,
            $international && !$pickupPoint  => $cod ? self::ConnectCod : self::Connect,
            default                          => $cod ? self::SmartEuropeCod : self::SmartEurope,
        };
    }

    public function isInternational(): bool
    {
        return in_array($this, [self::Connect, self::ConnectCod, self::SmartEurope, self::SmartEuropeCod], true);
    }
}
