<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Tests\Common\Http;

use Ages\ShippingGateway\Common\Http\AccessToken;
use Ages\ShippingGateway\Common\Http\TokenCache;
use Ages\ShippingGateway\Common\ShippingException;
use PHPUnit\Framework\TestCase;

final class TokenCacheTest extends TestCase
{
    private int $now = 1_000_000;

    /** @var list<string> */
    private array $fetchedKeys = [];

    private function cache(?int $expiresIn): TokenCache
    {
        return new TokenCache(
            function (string $key) use ($expiresIn): AccessToken {
                $this->fetchedKeys[] = $key;
                return new AccessToken($key . '-' . count($this->fetchedKeys), $expiresIn);
            },
            fn (): int => $this->now,
        );
    }

    public function testTokenIsCachedUntilShortlyBeforeExpiry(): void
    {
        $cache = $this->cache(1800);

        self::assertSame('default-1', $cache->get());
        $this->now += 1800 - TokenCache::ExpiryMarginSeconds - 1;
        self::assertSame('default-1', $cache->get());

        $this->now += 1;
        self::assertSame('default-2', $cache->get());
        self::assertCount(2, $this->fetchedKeys);
    }

    public function testFallbackTtlWhenCarrierDoesNotSendExpiry(): void
    {
        $cache = $this->cache(null);

        $cache->get();
        $this->now += TokenCache::DefaultTtlSeconds - TokenCache::ExpiryMarginSeconds - 1;
        $cache->get();
        self::assertCount(1, $this->fetchedKeys);

        $this->now += 1;
        $cache->get();
        self::assertCount(2, $this->fetchedKeys);
    }

    public function testVeryShortExpiryStillUsesTokenOnce(): void
    {
        $cache = $this->cache(30);

        self::assertSame('default-1', $cache->get());
        $this->now += 14;
        self::assertSame('default-1', $cache->get());
        $this->now += 1;
        self::assertSame('default-2', $cache->get());
    }

    public function testKeysAreCachedIndependently(): void
    {
        $cache = $this->cache(1800);

        self::assertSame('read-1', $cache->get('read'));
        self::assertSame('write-2', $cache->get('write'));
        self::assertSame('read-1', $cache->get('read'));

        $cache->invalidate('read');
        self::assertSame('read-3', $cache->get('read'));
        self::assertSame('write-2', $cache->get('write'));
        self::assertSame(['read', 'write', 'read'], $this->fetchedKeys);
    }

    public function testAccessTokenFromOAuthResponse(): void
    {
        $token = AccessToken::fromResponse(['access_token' => 'abc', 'expires_in' => 1800, 'token_type' => 'Bearer'], 'PPL');
        self::assertSame('abc', $token->value);
        self::assertSame(1800, $token->expiresIn);

        $token = AccessToken::fromResponse(['accessToken' => 'xyz', 'expiresIn' => '3600'], 'One by Allegro', 'accessToken', 'expiresIn');
        self::assertSame('xyz', $token->value);
        self::assertSame(3600, $token->expiresIn);

        self::assertNull(AccessToken::fromResponse(['access_token' => 'abc', 'expires_in' => 0], 'PPL')->expiresIn);
    }

    public function testAccessTokenMissingInResponse(): void
    {
        $this->expectException(ShippingException::class);
        $this->expectExceptionMessage('GBW: token was not created');
        AccessToken::fromResponse(['error' => 'invalid_client'], 'GBW');
    }
}
