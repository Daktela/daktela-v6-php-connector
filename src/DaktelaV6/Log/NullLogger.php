<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * A null logger that discards all log messages.
 * Used as default when no logger is provided.
 *
 * @package Daktela\DaktelaV6\Log
 */
class NullLogger implements LoggerInterface
{
    use LoggerTrait;

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        // Intentionally empty - discards all logs
    }
}
