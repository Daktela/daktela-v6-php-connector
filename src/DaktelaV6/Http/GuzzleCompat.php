<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Http;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\DiagnosticValue;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Keeps everything that differs between Guzzle 7 (with PSR-7 2) and Guzzle 8 (with PSR-7 3) in one place, so
 * that the transport works with either major version. Guzzle 8 is recognized by its features, not its version:
 * it keeps the response only on ResponseException and has no handler context on its exceptions.
 * @internal
 * @package Daktela\DaktelaV6\Http
 */
final class GuzzleCompat
{
    /** @var int[] cURL error numbers Guzzle 7 reports for failures before the request is sent (resolve/connect/TLS handshake) */
    private const CURL_ERRORS_BEFORE_SEND = [6, 7, 35];
    /** @var string[] Guzzle 7 classes a copied request exception can have, most specific first */
    private const GUZZLE7_REQUEST_EXCEPTIONS = [
        ClientException::class,
        ServerException::class,
        BadResponseException::class,
        TooManyRedirectsException::class,
        RequestException::class,
    ];
    /** @var string[] Guzzle 8 classes a copied network exception can have, most specific first */
    private const GUZZLE8_NETWORK_EXCEPTIONS = [
        ConnectTimeoutException::class,
        ConnectException::class,
        NetworkTimeoutException::class,
        NetworkException::class,
    ];
    /** @var string[] Guzzle 8 classes a copied exception with a response can have, most specific first */
    private const GUZZLE8_RESPONSE_EXCEPTIONS = [
        ClientException::class,
        ServerException::class,
        BadResponseException::class,
        TooManyRedirectsException::class,
        ResponseTimeoutException::class,
        ResponseTransferException::class,
        ResponseException::class,
    ];

    /**
     * @return bool whether Guzzle 8 or later is installed
     */
    public static function isGuzzle8(): bool
    {
        return class_exists(ResponseException::class);
    }

    /**
     * Returns the response of an HTTP client exception. Guzzle 7 keeps it, when there is one, on every request
     * exception; Guzzle 8 only on ResponseException.
     * @param \Throwable $ex exception raised by the HTTP client
     * @return ResponseInterface|null response, or null when the exception has none
     */
    public static function responseOf(\Throwable $ex): ?ResponseInterface
    {
        $response = $ex instanceof RequestException && method_exists($ex, 'getResponse') ? $ex->getResponse() : null;

        return $response instanceof ResponseInterface ? $response : null;
    }

    /**
     * Returns the handler context of an HTTP client exception, e.g. the error number and request headers cURL
     * reports. Guzzle 8 has no handler context.
     * @param \Throwable $ex exception raised by the HTTP client
     * @return array handler context, or an empty array
     */
    public static function handlerContext(\Throwable $ex): array
    {
        if (!$ex instanceof TransferException || !method_exists($ex, 'getHandlerContext')) {
            return [];
        }
        $context = $ex->getHandlerContext();

        return is_array($context) ? $context : [];
    }

    /**
     * Checks whether a network failure happened before the request was sent to the server, so that repeating
     * it cannot duplicate a server-side operation. Guzzle 7 reports timeouts as ConnectException as well, so the
     * cURL error number decides; Guzzle 8 throws ConnectException only when the connection could not be
     * established (DNS resolution, TCP connect, proxy CONNECT, or TLS handshake).
     * @param \Throwable $ex exception raised by the HTTP client
     * @return bool true when the request was not sent
     */
    public static function failedBeforeSend(\Throwable $ex): bool
    {
        if (!$ex instanceof ConnectException) {
            return false;
        }
        if (self::isGuzzle8()) {
            return true;
        }
        $errno = self::handlerContext($ex)['errno'] ?? null;

        return is_int($errno) && in_array($errno, self::CURL_ERRORS_BEFORE_SEND, true);
    }

    /**
     * Creates a copy of a Guzzle exception from parts that no longer carry the access token. The copy has the
     * class of the given exception, or of its nearest Guzzle ancestor when a custom subclass cannot be created
     * with the Guzzle constructor arguments.
     * @param TransferException $ex network or request exception to copy
     * @param string $message message of the copy
     * @param RequestInterface $request request of the copy
     * @param ResponseInterface|null $response response of the copy, or null when the exception has none
     * @param array $context handler context of the copy; Guzzle 8 does not keep one
     * @return TransferException|null copy, or null when the exception cannot be copied
     */
    public static function copy(
        TransferException $ex,
        string $message,
        RequestInterface $request,
        ?ResponseInterface $response,
        array $context
    ): ?TransferException {
        if (!self::isGuzzle8()) {
            if ($ex instanceof ConnectException) {
                return self::create([ConnectException::class], [$message, $request, null, $context]);
            }
            if ($ex instanceof RequestException) {
                $classes = self::withAncestors($ex, self::GUZZLE7_REQUEST_EXCEPTIONS);

                return self::create($classes, [$message, $request, $response, null, $context]);
            }

            return null;
        }

        if ($ex instanceof NetworkException) {
            return self::create(self::withAncestors($ex, self::GUZZLE8_NETWORK_EXCEPTIONS), [$message, $request]);
        }
        if ($ex instanceof ResponseException && $response !== null) {
            // The constructor of ResponseException is final, so every subclass takes these arguments; the
            // ancestors are a fallback all the same
            $classes = self::withAncestors($ex, self::GUZZLE8_RESPONSE_EXCEPTIONS);

            return self::create($classes, [$message, $request, $response]);
        }
        if ($ex instanceof RequestException) {
            // A request failure without a response, e.g. while reading the request body
            $classes = self::withAncestors($ex, [RequestException::class]);

            return self::create($classes, [$message, $request, (int)$ex->getCode()]);
        }

        return null;
    }

    /**
     * Escapes control characters, such as line breaks, as Guzzle 8 does with the values it puts in exception
     * messages. Without PSR-7 3, which provides the escaping, the text is returned unchanged.
     * @param string $text text, e.g. a response body summary
     * @return string text as Guzzle 8 puts it in a message
     */
    public static function escapeForMessage(string $text): string
    {
        return class_exists(DiagnosticValue::class) ? DiagnosticValue::escape($text) : $text;
    }

    /**
     * @param TransferException $ex exception to copy
     * @param string[] $guzzleClasses Guzzle classes the copy can have, most specific first
     * @return string[] the class of the exception, then its ancestors among the given Guzzle classes
     */
    private static function withAncestors(TransferException $ex, array $guzzleClasses): array
    {
        // class_parents() does not list the class itself
        return array_merge([get_class($ex)], array_intersect($guzzleClasses, class_parents($ex)));
    }

    /**
     * @param string[] $classes classes to try, in order
     * @param array $arguments constructor arguments
     * @return TransferException|null instance of the first class that accepts the arguments, or null
     */
    private static function create(array $classes, array $arguments): ?TransferException
    {
        foreach ($classes as $class) {
            try {
                $copy = new $class(...$arguments);
            } catch (\Throwable $constructorError) {
                // A custom subclass with other constructor arguments: try the nearest Guzzle class
                continue;
            }
            if ($copy instanceof TransferException) {
                return $copy;
            }
        }

        return null;
    }
}
