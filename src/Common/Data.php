<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common;

/**
 * Bezpečné čtení hodnot z dekódovaných odpovědí dopravců (pole i stdClass, libovolně vnořené).
 * Chybějící klíč nebo hodnota jiného typu vrací výchozí hodnotu místo varování / TypeError.
 *
 * Cesta je klíč, nebo seznam klíčů do hloubky: Data::string($json, ['receiver', 'address', 'country']).
 */
final class Data
{
    private function __construct()
    {
    }

    /**
     * @param string|int|list<string|int> $path
     */
    public static function get(mixed $source, string|int|array $path): mixed
    {
        $value = $source;
        foreach (is_array($path) ? $path : [$path] as $key) {
            if (is_array($value) && array_key_exists($key, $value)) {
                $value = $value[$key];
            } elseif ($value instanceof \stdClass && property_exists($value, (string) $key)) {
                $value = $value->{$key};
            } else {
                return null;
            }
        }
        return $value;
    }

    /**
     * Skalár převedený na řetězec (čísla z JSON chodí i jako int), jinak $default.
     *
     * @param string|int|list<string|int> $path
     */
    public static function string(mixed $source, string|int|array $path, string $default = ''): string
    {
        $value = self::get($source, $path);
        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Skalár převedený na řetězec, jinak null (na rozdíl od nonEmptyString() nechá i '').
     *
     * @param string|int|list<string|int> $path
     */
    public static function nullableString(mixed $source, string|int|array $path): ?string
    {
        $value = self::get($source, $path);
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Neprázdný řetězec, jinak null.
     *
     * @param string|int|list<string|int> $path
     */
    public static function nonEmptyString(mixed $source, string|int|array $path): ?string
    {
        $value = self::string($source, $path);
        return $value !== '' ? $value : null;
    }

    /**
     * Číslo nebo číselný řetězec, jinak $default.
     *
     * @param string|int|list<string|int> $path
     */
    public static function float(mixed $source, string|int|array $path, float $default = 0.0): float
    {
        $value = self::get($source, $path);
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : $default;
    }

    /**
     * Pole na dané cestě (seznam i mapa), jinak [].
     *
     * @param string|int|list<string|int> $path
     * @return array<mixed>
     */
    public static function array(mixed $source, string|int|array $path): array
    {
        $value = self::get($source, $path);
        return is_array($value) ? $value : [];
    }

    /**
     * Položky seznamu, které jsou pole (JSON dekódovaný jako asociativní pole).
     *
     * @param string|int|list<string|int> $path
     * @return list<array<mixed>>
     */
    public static function arrays(mixed $source, string|int|array $path): array
    {
        return array_values(array_filter(self::array($source, $path), 'is_array'));
    }

    /**
     * Položky seznamu, které jsou objekty (JSON dekódovaný jako stdClass).
     *
     * @param string|int|list<string|int> $path
     * @return list<\stdClass>
     */
    public static function objects(mixed $source, string|int|array $path): array
    {
        return array_values(array_filter(self::array($source, $path), static fn (mixed $item): bool => $item instanceof \stdClass));
    }

    /**
     * Datum z řetězce; prázdný nebo neplatný → null.
     */
    public static function date(mixed $value, ?\DateTimeZone $timezone = null): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
        return $timezone !== null ? $date->setTimezone($timezone) : $date;
    }
}
