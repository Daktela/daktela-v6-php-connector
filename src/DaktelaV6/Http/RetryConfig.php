<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Http;

/**
 * Configuration for retry behavior with exponential backoff.
 *
 * @package Daktela\DaktelaV6\Http
 */
class RetryConfig
{
    /**
     * @param int $maxRetries Maximum number of retry attempts (0 = no retries)
     * @param int $baseDelayMs Base delay in milliseconds before first retry
     * @param int $maxDelayMs Maximum delay in milliseconds between retries
     * @param float $multiplier Multiplier for exponential backoff (e.g., 2.0 doubles delay each attempt)
     * @param array $retryableStatusCodes HTTP status codes that should trigger a retry
     * @param bool $retryOnConnectionError Whether to retry on connection errors
     */
    public function __construct(
        private int $maxRetries = 3,
        private int $baseDelayMs = 100,
        private int $maxDelayMs = 10000,
        private float $multiplier = 2.0,
        private array $retryableStatusCodes = [408, 500, 502, 503, 504],
        private bool $retryOnConnectionError = true,
    ) {
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function getBaseDelayMs(): int
    {
        return $this->baseDelayMs;
    }

    public function getMaxDelayMs(): int
    {
        return $this->maxDelayMs;
    }

    public function getMultiplier(): float
    {
        return $this->multiplier;
    }

    public function getRetryableStatusCodes(): array
    {
        return $this->retryableStatusCodes;
    }

    public function shouldRetryOnConnectionError(): bool
    {
        return $this->retryOnConnectionError;
    }

    /**
     * Calculate delay for a given attempt (0-indexed).
     *
     * @param int $attempt Attempt number (0 = first retry)
     * @return int Delay in milliseconds
     */
    public function getDelayForAttempt(int $attempt): int
    {
        $delay = (int)($this->baseDelayMs * pow($this->multiplier, $attempt));
        return min($delay, $this->maxDelayMs);
    }

    /**
     * Check if a status code should trigger a retry.
     *
     * @param int $statusCode HTTP status code
     * @return bool True if the status code is retryable
     */
    public function isRetryableStatus(int $statusCode): bool
    {
        return in_array($statusCode, $this->retryableStatusCodes, true);
    }

    /**
     * Factory for disabled retries.
     *
     * @return self
     */
    public static function disabled(): self
    {
        return new self(maxRetries: 0);
    }

    /**
     * Factory for aggressive retry configuration.
     * Useful for critical operations that must succeed.
     *
     * @return self
     */
    public static function aggressive(): self
    {
        return new self(
            maxRetries: 5,
            baseDelayMs: 50,
            maxDelayMs: 30000,
            multiplier: 2.5,
        );
    }
}
