<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Http;

use Ages\ShippingGateway\Common\ShippingException;

/**
 * Přístupový token a jeho platnost v sekundách (null = dopravce ji neuvádí → výchozí TTL v TokenCache).
 */
final readonly class AccessToken
{
    public function __construct(
        public string $value,
        public ?int $expiresIn = null,
    ) {
    }

    /**
     * Token z JSON odpovědi (OAuth `access_token` / `expires_in`, klíče lze přepsat).
     *
     * @param array<mixed> $data
     * @throws ShippingException token v odpovědi chybí
     */
    public static function fromResponse(array $data, string $carrier, string $tokenKey = 'access_token', string $expiresKey = 'expires_in'): self
    {
        $token = $data[$tokenKey] ?? null;
        if (!is_string($token) || $token === '') {
            throw new ShippingException($carrier . ': token was not created');
        }

        $expiresIn = $data[$expiresKey] ?? null;
        if (is_string($expiresIn) && ctype_digit($expiresIn)) {
            $expiresIn = (int) $expiresIn;
        }

        return new self($token, is_int($expiresIn) && $expiresIn > 0 ? $expiresIn : null);
    }
}
