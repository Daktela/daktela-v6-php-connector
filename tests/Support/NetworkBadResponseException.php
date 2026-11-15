<?php

declare(strict_types=1);

namespace Daktela\Tests\Support;

use GuzzleHttp\Exception\BadResponseException;
use Psr\Http\Client\NetworkExceptionInterface;

/**
 * A custom Guzzle exception with a response that also reports itself as a network failure. The constructor of
 * BadResponseException takes the same arguments on Guzzle 7 and Guzzle 8.
 */
class NetworkBadResponseException extends BadResponseException implements NetworkExceptionInterface
{
}
