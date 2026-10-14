<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Auth;

use InvalidArgumentException;

/**
 * Access and refresh token of a session started by logging in with a username and password.
 * Both are JWTs; their expiry is read from the `exp` claim without verifying the signature,
 * which only the Daktela server can do.
 * @package Daktela\DaktelaV6\Auth
 */
final class TokenSet
{
    private string $accessToken;
    private string $refreshToken;
    private ?int $accessTokenExpiresAt;
    private ?int $refreshTokenExpiresAt;
    private ?string $owner;

    private function __construct(
        string $accessToken,
        string $refreshToken,
        ?int $accessTokenExpiresAt,
        ?int $refreshTokenExpiresAt,
        ?string $owner
    ) {
        if ($accessToken === '' || $refreshToken === '') {
            throw new InvalidArgumentException('Access token and refresh token must not be empty');
        }
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
        $this->accessTokenExpiresAt = $accessTokenExpiresAt;
        $this->refreshTokenExpiresAt = $refreshTokenExpiresAt;
        $this->owner = $owner;
    }

    /**
     * @param string $accessToken access token returned by the API
     * @param string $refreshToken refresh token returned by the API
     * @param string|null $owner session the tokens were issued for, as `username@instance URL`
     * @return self tokens with the expiry read from their `exp` claims
     */
    public static function fromTokens(string $accessToken, string $refreshToken, ?string $owner = null): self
    {
        return new self($accessToken, $refreshToken, self::expiry($accessToken), self::expiry($refreshToken), $owner);
    }

    /**
     * Restores tokens saved with toArray(), e.g. by a TokenStore.
     * @param array $data array returned by toArray()
     * @return self restored tokens
     * @throws InvalidArgumentException when the array does not contain both tokens
     */
    public static function fromArray(array $data): self
    {
        if (!is_string($data['accessToken'] ?? null) || !is_string($data['refreshToken'] ?? null)) {
            throw new InvalidArgumentException('Saved tokens must contain accessToken and refreshToken');
        }

        $owner = is_string($data['owner'] ?? null) ? $data['owner'] : null;

        return self::fromTokens($data['accessToken'], $data['refreshToken'], $owner);
    }

    /**
     * @return array{accessToken: string, refreshToken: string, owner?: string} tokens to be saved, e.g. as JSON
     */
    public function toArray(): array
    {
        $data = ['accessToken' => $this->accessToken, 'refreshToken' => $this->refreshToken];
        if ($this->owner !== null) {
            $data['owner'] = $this->owner;
        }

        return $data;
    }

    /**
     * @return string|null session the tokens were issued for, as `username@instance URL`, or null when unknown
     */
    public function getOwner(): ?string
    {
        return $this->owner;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function getRefreshToken(): string
    {
        return $this->refreshToken;
    }

    /**
     * @return int|null Unix time the access token expires at, or null when unknown
     */
    public function getAccessTokenExpiresAt(): ?int
    {
        return $this->accessTokenExpiresAt;
    }

    /**
     * @return int|null Unix time the refresh token expires at, or null when unknown
     */
    public function getRefreshTokenExpiresAt(): ?int
    {
        return $this->refreshTokenExpiresAt;
    }

    /**
     * @param int $seconds margin before the expiry
     * @param int $now current Unix time
     * @return bool true when the access token expires within the margin; false when its expiry is unknown
     */
    public function accessTokenExpiresWithin(int $seconds, int $now): bool
    {
        return $this->accessTokenExpiresAt !== null && $this->accessTokenExpiresAt - $seconds <= $now;
    }

    /**
     * @param int $now current Unix time
     * @return bool true when the refresh token has expired; false when its expiry is unknown
     */
    public function isRefreshTokenExpired(int $now): bool
    {
        return $this->refreshTokenExpiresAt !== null && $this->refreshTokenExpiresAt <= $now;
    }

    /**
     * @param string $token JWT
     * @return int|null value of the `exp` claim, or null when the token is not a JWT with one
     */
    private static function expiry(string $token): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $payload = $json === false ? null : json_decode($json, true);

        return is_array($payload) && is_int($payload['exp'] ?? null) ? $payload['exp'] : null;
    }
}
