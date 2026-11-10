<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Toptrans\Values;

use Ages\ShippingGateway\Common\ShippingException;

/**
 * Měna (číselník register/currency).
 */
enum Currency: int
{
    case Czk = 1;
    case Eur = 2;

    public static function fromCode(string $code): self
    {
        return match (strtoupper(trim($code))) {
            'CZK' => self::Czk,
            'EUR' => self::Eur,
            default => throw new ShippingException('Toptrans: unsupported currency ' . $code . ' (CZK, EUR)'),
        };
    }
}
