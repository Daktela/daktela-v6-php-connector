<?php

declare(strict_types=1);

namespace Daktela\Tests\Support;

use GuzzleHttp\Exception\ClientException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A custom ClientException whose constructor takes other arguments than Guzzle's. Guzzle 8 does not allow it
 * (the constructor of ResponseException is final), so it is in its own file and loaded only on Guzzle 7.
 */
class Guzzle7StrictClientException extends ClientException
{
    public function __construct(RequestInterface $request, ResponseInterface $response)
    {
        parent::__construct('Strict client failure', $request, $response);
    }
}
