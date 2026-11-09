<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\OneByAllegro;

/**
 * Úprava adresních údajů na formát, který One by Allegro API přijme.
 */
final class AddressFormatter
{
    private const int NameLimit = 30;
    private const array PhonePrefixes = ['CZ' => '420', 'SK' => '421'];

    private function __construct()
    {
    }

    /**
     * API chce ulici a číslo zvlášť. Když číslo chybí, oddělí se z konce ulice („Hlavní 42/5“ → Hlavní, 42/5).
     *
     * @return array{string, string}
     */
    public static function splitStreet(string $street, ?string $houseNumber): array
    {
        $street = trim($street);
        $houseNumber = $houseNumber !== null ? trim($houseNumber) : '';
        if ($houseNumber !== '') {
            return [$street, $houseNumber];
        }

        if (preg_match('~^(.*\S)[\s,]+(\d[\p{L}\d/\-]*)$~u', $street, $m) === 1) {
            return [trim($m[1], " ,"), $m[2]];
        }

        // obec bez ulic: „Lhota 12“ by se rozdělila výše, samotné číslo „12“ → ulice = číslo
        if (preg_match('~^\d[\p{L}\d/\-]*$~u', $street) === 1) {
            return [$street, $street];
        }

        return [$street, ''];
    }

    /**
     * Mezinárodní tvar +420XXXXXXXXX / +421XXXXXXXXX.
     */
    public static function phone(string $phone, string $country): string
    {
        $phone = trim($phone);
        $digits = (string) preg_replace('~\D+~', '', $phone);
        if ($digits === '') {
            return '';
        }

        if (str_starts_with($phone, '+')) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '00')) {
            return '+' . substr($digits, 2);
        }

        $prefix = self::PhonePrefixes[strtoupper($country)] ?? null;
        if ($prefix === null) {
            return '+' . $digits;
        }
        if (strlen($digits) === 12 && str_starts_with($digits, $prefix)) {
            return '+' . $digits;
        }

        return '+' . $prefix . ltrim($digits, '0');
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
