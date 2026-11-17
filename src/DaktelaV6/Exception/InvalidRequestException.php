<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Exception;

/**
 * Thrown before anything is sent, when the request or the configuration of the connector is not valid: a model
 * or an object name that would address another endpoint, a relation without its object, a page size below 1,
 * an instance URL that cannot be parsed, or an unknown authentication method. Sending the same request again
 * fails the same way.
 */
class InvalidRequestException extends RequestException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
