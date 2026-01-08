<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Exception;

/**
 * Exception thrown when rate limit is exceeded and auto-retry is disabled
 * or the wait time exceeds the configured maximum.
 *
 * @package Daktela\DaktelaV6\Exception
 */
class RateLimitException extends RequestException
{
    private int $retryAfterSeconds;

    /**
     * @param int $retryAfterSeconds Number of seconds to wait before retrying
     * @param \Throwable|null $previous Previous exception if any
     */
    public function __construct(int $retryAfterSeconds, ?\Throwable $previous = null)
    {
        $this->retryAfterSeconds = $retryAfterSeconds;
        parent::__construct(
            "Rate limit exceeded. Retry after {$retryAfterSeconds} seconds.",
            429,
            $previous
        );
    }

    /**
     * Get the number of seconds to wait before retrying.
     *
     * @return int Seconds to wait
     */
    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
