<?php

namespace Daktela\DaktelaV6\Exception;

use Throwable;

/**
 * Thrown when the API responds with HTTP 404 or when a request lacks the object name it needs.
 */
class NotFoundException extends RequestException
{
    /**
     * @param string $message exception message
     * @param Throwable|null $previous previous exception
     * @param string|null $responseBody raw body of the HTTP 404 response, if any
     * @param array $apiErrors errors reported by the API in the HTTP 404 response
     */
    public function __construct($message, ?Throwable $previous = null, ?string $responseBody = null, array $apiErrors = [])
    {
        parent::__construct($message, 404, $previous, $responseBody === null ? null : 404, $responseBody, $apiErrors);
    }
}
