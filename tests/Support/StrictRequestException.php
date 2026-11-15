<?php

declare(strict_types=1);

namespace Daktela\Tests\Support;

use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\RequestInterface;

/**
 * A custom Guzzle request exception without a response whose constructor takes other arguments than Guzzle's.
 * Its parent constructor call is valid on Guzzle 7 and Guzzle 8: the previous exception is passed by name,
 * because its position differs between the two versions.
 */
class StrictRequestException extends RequestException
{
    public function __construct(RequestInterface $request, ?\Throwable $previous = null)
    {
        parent::__construct('Strict request failure', $request, previous: $previous);
    }
}
