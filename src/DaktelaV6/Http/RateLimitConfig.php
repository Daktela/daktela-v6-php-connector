<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Http;

/**
 * Configuration for rate limit handling (HTTP 429 responses).
 *
 * @package Daktela\DaktelaV6\Http
 */
class RateLimitConfig
{
    /**
     * @param bool $autoRetry Whether to automatically wait and retry on rate limit
     * @param int $maxWaitSeconds Maximum time to wait for rate limit reset (throws exception if exceeded)
     * @param int $defaultWaitSeconds Default wait time if Retry-After header is missing
     */
    public function __construct(
        private bool $autoRetry = true,
        private int $maxWaitSeconds = 60,
        private int $defaultWaitSeconds = 5,
    ) {
    }

    public function shouldAutoRetry(): bool
    {
        return $this->autoRetry;
    }

    public function getMaxWaitSeconds(): int
    {
        return $this->maxWaitSeconds;
    }

    public function getDefaultWaitSeconds(): int
    {
        return $this->defaultWaitSeconds;
    }

    /**
     * Parse Retry-After header value.
     * The header can be either an integer (seconds) or an HTTP date.
     *
     * @param string|null $headerValue Value of the Retry-After header
     * @return int Wait time in seconds
     */
    public function parseRetryAfter(?string $headerValue): int
    {
        if ($headerValue === null || $headerValue === '') {
            return $this->defaultWaitSeconds;
        }

        // Try parsing as integer seconds
        if (is_numeric($headerValue)) {
            return (int)$headerValue;
        }

        // Try parsing as HTTP date
        $timestamp = strtotime($headerValue);
        if ($timestamp !== false) {
            return max(0, $timestamp - time());
        }

        return $this->defaultWaitSeconds;
    }
}
