<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Common\Http;

/**
 * Cache přístupových tokenů s expirací. Token se obnoví s rezervou před vypršením
 * (dlouho běžící procesy, např. cron s hromadným trackingem, jinak skončí na 401).
 *
 * Klíč odlišuje víc tokenů jednoho dopravce (Gebrüder Weiss má token pro každý scope).
 */
final class TokenCache
{
    public const string DefaultKey = 'default';
    /** Platnost, když ji dopravce v odpovědi neuvede. */
    public const int DefaultTtlSeconds = 25 * 60;
    /** Rezerva před vypršením. */
    public const int ExpiryMarginSeconds = 60;

    /** @var array<string, array{token: string, expiresAt: int}> */
    private array $tokens = [];

    /** @var \Closure(string): AccessToken */
    private readonly \Closure $fetcher;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param \Closure(string): AccessToken $fetcher získá nový token pro klíč
     * @param (\Closure(): int)|null        $clock   aktuální unix čas (pro testy)
     */
    public function __construct(\Closure $fetcher, ?\Closure $clock = null)
    {
        $this->fetcher = $fetcher;
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function get(string $key = self::DefaultKey): string
    {
        $now = ($this->clock)();
        $cached = $this->tokens[$key] ?? null;
        if ($cached !== null && $now < $cached['expiresAt']) {
            return $cached['token'];
        }

        $token = ($this->fetcher)($key);
        $ttl = $token->expiresIn ?? self::DefaultTtlSeconds;
        // u velmi krátké platnosti rezervu omezit na polovinu, ať se token použije aspoň jednou
        $margin = min(self::ExpiryMarginSeconds, intdiv($ttl, 2));
        $this->tokens[$key] = ['token' => $token->value, 'expiresAt' => $now + $ttl - $margin];

        return $token->value;
    }

    /**
     * Zahodí token (např. po 401), další get() získá nový.
     */
    public function invalidate(string $key = self::DefaultKey): void
    {
        unset($this->tokens[$key]);
    }
}
