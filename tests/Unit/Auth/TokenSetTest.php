<?php

declare(strict_types=1);

namespace Daktela\Tests\Unit\Auth;

use Daktela\DaktelaV6\Auth\InMemoryTokenStore;
use Daktela\DaktelaV6\Auth\TokenSet;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TokenSetTest extends TestCase
{
    /**
     * Builds an unsigned JWT with the given expiry, as the connector never verifies the signature.
     */
    public static function jwt(?int $expiresAt): string
    {
        $encode = static fn (array $part): string => rtrim(strtr(base64_encode((string)json_encode($part)), '+/', '-_'), '=');
        $payload = $expiresAt === null ? ['u' => 1] : ['u' => 1, 'exp' => $expiresAt];

        return $encode(['typ' => 'JWT', 'alg' => 'HS256']) . '.' . $encode($payload) . '.signature';
    }

    public function testReadsExpiryFromTokens(): void
    {
        $tokens = TokenSet::fromTokens(self::jwt(1000), self::jwt(5000));

        $this->assertSame(1000, $tokens->getAccessTokenExpiresAt());
        $this->assertSame(5000, $tokens->getRefreshTokenExpiresAt());
    }

    public function testExpiryIsUnknownForTokensWithoutExpClaim(): void
    {
        $tokens = TokenSet::fromTokens('not-a-jwt', self::jwt(null));

        $this->assertNull($tokens->getAccessTokenExpiresAt());
        $this->assertNull($tokens->getRefreshTokenExpiresAt());
        $this->assertFalse($tokens->accessTokenExpiresWithin(60, 2000));
        $this->assertFalse($tokens->isRefreshTokenExpired(2000));
    }

    public function testReportsExpiringAndExpiredTokens(): void
    {
        $tokens = TokenSet::fromTokens(self::jwt(1000), self::jwt(5000));

        $this->assertFalse($tokens->accessTokenExpiresWithin(60, 900));
        $this->assertTrue($tokens->accessTokenExpiresWithin(60, 950));
        $this->assertFalse($tokens->isRefreshTokenExpired(4999));
        $this->assertTrue($tokens->isRefreshTokenExpired(5000));
    }

    public function testConvertsToArrayAndBack(): void
    {
        $tokens = TokenSet::fromTokens(self::jwt(1000), self::jwt(5000));

        $copy = TokenSet::fromArray($tokens->toArray());

        $this->assertSame($tokens->getAccessToken(), $copy->getAccessToken());
        $this->assertSame($tokens->getRefreshToken(), $copy->getRefreshToken());
        $this->assertSame(1000, $copy->getAccessTokenExpiresAt());
    }

    public function testKeepsOwnerInArray(): void
    {
        $tokens = TokenSet::fromTokens('access', 'refresh', 'api@https://example.com');

        $this->assertSame('api@https://example.com', TokenSet::fromArray($tokens->toArray())->getOwner());
        $this->assertNull(TokenSet::fromArray(['accessToken' => 'a', 'refreshToken' => 'r'])->getOwner());
    }

    public function testRejectsArrayWithoutTokens(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TokenSet::fromArray(['accessToken' => 'a']);
    }

    public function testRejectsEmptyTokens(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TokenSet::fromTokens('', 'refresh');
    }

    public function testInMemoryStoreKeepsLastSavedTokens(): void
    {
        $store = new InMemoryTokenStore();
        $this->assertNull($store->load());

        $tokens = TokenSet::fromTokens('access', 'refresh');
        $store->save($tokens);

        $this->assertSame($tokens, $store->load());
    }
}
