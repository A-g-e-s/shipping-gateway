<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common;

/**
 * Úpravy adres společné pro dopravce, kteří chtějí ulici a číslo zvlášť a telefon v mezinárodním tvaru.
 */
final class AddressFormatter
{
    /** Předvolby pro čísla zadaná bez ní. */
    private const array PhonePrefixes = [
        'CZ' => '420', 'SK' => '421', 'PL' => '48', 'DE' => '49', 'AT' => '43', 'HU' => '36',
    ];
    /** Délka národního čísla (bez předvolby). */
    private const int NationalNumberLength = 9;

    private function __construct()
    {
    }

    /**
     * Když číslo domu chybí, oddělí se z konce ulice („Hlavní 42/5“ → Hlavní, 42/5).
     *
     * @return array{string, string} [ulice, číslo]
     */
    public static function splitStreet(string $street, ?string $houseNumber): array
    {
        $street = trim($street);
        $houseNumber = $houseNumber !== null ? trim($houseNumber) : '';
        if ($houseNumber !== '') {
            return [$street, $houseNumber];
        }

        if (preg_match('~^(.*\S)[\s,]+(\d[\p{L}\d/\-]*)$~u', $street, $m) === 1) {
            return [trim($m[1], ' ,'), $m[2]];
        }

        // samotné číslo („12“) – obec bez ulic
        if (preg_match('~^\d[\p{L}\d/\-]*$~u', $street) === 1) {
            return [$street, $street];
        }

        return [$street, ''];
    }

    /**
     * Mezinárodní tvar „+420123456789“. Číslo bez předvolby dostane předvolbu podle země.
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
        if (str_starts_with($digits, $prefix) && strlen($digits) > self::NationalNumberLength) {
            return '+' . $digits;
        }

        return '+' . $prefix . ltrim($digits, '0');
    }
}
