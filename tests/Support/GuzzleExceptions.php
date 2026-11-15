<?php

declare(strict_types=1);

namespace Daktela\Tests\Support;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Exception\TransferException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Builds the exceptions the installed Guzzle major version throws for a failure, so that one test suite runs
 * against Guzzle 7 and Guzzle 8.
 */
final class GuzzleExceptions
{
    public static function isGuzzle8(): bool
    {
        return class_exists(ResponseException::class);
    }

    public static function requireGuzzle7(TestCase $test): void
    {
        if (self::isGuzzle8()) {
            $test->markTestSkipped('Guzzle 7 only');
        }
    }

    public static function requireGuzzle8(TestCase $test): void
    {
        if (!self::isGuzzle8()) {
            $test->markTestSkipped('Guzzle 8 only');
        }
    }

    /**
     * A connection that could not be established (Guzzle 7 reports cURL error 7), so the request was not sent.
     */
    public static function connectFailure(
        RequestInterface $request,
        string $message = 'cURL error 7: Failed to connect'
    ): ConnectException {
        return self::isGuzzle8()
            ? new ConnectException($message, $request)
            : new ConnectException($message, $request, null, ['errno' => 7]);
    }

    /**
     * A timeout before the response headers (Guzzle 7 reports cURL error 28 as a ConnectException), so the server
     * may have received the request.
     */
    public static function timeoutBeforeResponse(
        RequestInterface $request,
        string $message = 'cURL error 28: Operation timed out'
    ): TransferException {
        return self::isGuzzle8()
            ? new NetworkTimeoutException($message, $request)
            : new ConnectException($message, $request, null, ['errno' => 28]);
    }

    /**
     * A response that broke off after its headers, e.g. on a connection reset (Guzzle 7 reports cURL error 56 as
     * a RequestException with the partial response).
     */
    public static function brokenResponse(
        RequestInterface $request,
        ResponseInterface $response,
        string $message = 'cURL error 56: Recv failure'
    ): RequestException {
        return self::isGuzzle8()
            ? new ResponseTransferException($message, $request, $response)
            : new RequestException($message, $request, $response, null, ['errno' => 56]);
    }

    /**
     * The base Guzzle transfer exception, which Guzzle 8 creates with the failed request.
     */
    public static function transferException(string $message, RequestInterface $request): TransferException
    {
        return self::isGuzzle8() ? new TransferException($message, $request) : new TransferException($message);
    }
}
