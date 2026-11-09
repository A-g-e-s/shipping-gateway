<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\OneByAllegro;

use Ages\ShippingGateway\Common\AddressFormatter as CommonAddressFormatter;

/**
 * Úprava adresních údajů na formát, který One by Allegro API přijme (ulice a telefon přes Common\AddressFormatter).
 */
final class AddressFormatter
{
    private const int NameLimit = 30;

    private function __construct()
    {
    }

    /**
     * @return array{string, string}
     */
    public static function splitStreet(string $street, ?string $houseNumber): array
    {
        return CommonAddressFormatter::splitStreet($street, $houseNumber);
    }

    public static function phone(string $phone, string $country): string
    {
        return CommonAddressFormatter::phone($phone, $country);
    }

    /**
     * Jméno smí obsahovat jen písmena, mezery, apostrof a pomlčku (max 30 znaků).
     */
    public static function name(string $name): string
    {
        $name = (string) preg_replace("~[^\\p{L}\\s'-]+~u", ' ', $name);
        $name = trim((string) preg_replace('~\s+~u', ' ', $name));
        return mb_substr($name, 0, self::NameLimit);
    }

    /**
     * Popis obsahu: jen písmena, číslice, mezery, čárka a tečka.
     */
    public static function contentDescription(string $text): string
    {
        $text = (string) preg_replace('~[^\p{L}\s\d,.]+~u', ' ', $text);
        $text = trim((string) preg_replace('~\s+~u', ' ', $text));
        return mb_substr($text, 0, 50);
    }
}
