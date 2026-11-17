<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Http\Internal;

use Daktela\DaktelaV6\Exception\NotFoundException;
use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\Http\GuzzleCompat;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils as Psr7Utils;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Converts the exceptions of the HTTP client into connector exceptions that do not carry the access token.
 * @internal
 */
final class ExceptionFactory
{
    /** @var int Largest response body, in bytes, copied into an exception chained as the previous exception */
    private const COPIED_BODY_LIMIT = 1048576;

    /**
     * @param Redactor $redactor redaction of the secrets
     * @param bool $chainPrevious whether a copy of the client's exception is chained; not with query parameter
     *     authentication, where the token is part of the request URL
     */
    public function __construct(private readonly Redactor $redactor, private readonly bool $chainPrevious)
    {
    }

    /**
     * Converts an HTTP error response into the connector exception carrying the response details.
     * @param BadResponseException $ex exception raised by the HTTP client
     * @param ResponseInterface $response failed HTTP response
     * @return RequestException exception to be thrown
     */
    public function fromHttpError(BadResponseException $ex, ResponseInterface $response): RequestException
    {
        // PHP records the current values of the parameters in the trace of every exception created below this
        // frame, so the client's exception and the response, which may carry the token, are moved out of them
        [$original, $httpResponse] = [$ex, $response];
        $ex = $response = null;
        try {
            $body = (string)$httpResponse->getBody();
        } catch (\RuntimeException $readError) {
            // e.g. a detached or write-only body of a custom HTTP client
            $body = '';
        }
        $body = $this->redactor->responseBody($body);
        $decoded = json_decode($body);
        $apiErrors = is_object($decoded) ? $this->redactor->redactValues(ResponseParser::normalizeErrors($decoded->error ?? null)) : [];
        if ($this->redactor->loginInProgress()) {
            $apiErrors = $this->redactor->maskPassword($apiErrors);
        }
        $sanitized = $this->sanitize($original, $body);
        $message = $sanitized === null ? $this->redactor->redact($original->getMessage()) : $sanitized->getMessage();
        $previous = $this->chainPrevious ? $sanitized : null;
        $status = $httpResponse->getStatusCode();
        $code = $original->getCode();
        $original = $httpResponse = null;

        if ($status === 404) {
            return new NotFoundException($message, $previous, $body, $apiErrors);
        }

        return new RequestException($message, $code, $previous, $status, $body, $apiErrors);
    }

    /**
     * Wraps an exception of the HTTP client in a RequestException without the access token. The chained
     * previous exception is a copy without the token (see sanitize()), because loggers and error trackers
     * serialize the whole exception chain. With query parameter authentication nothing is chained.
     * @param Throwable $ex exception raised by the HTTP client
     * @param string $messagePrefix text put before the message
     * @return RequestException exception to be thrown
     */
    public function wrap(Throwable $ex, string $messagePrefix = ''): RequestException
    {
        // PHP records the current values of the parameters in the trace of every exception created below this
        // frame, so the client's exception, whose request carries the token, is moved out of them
        $original = $ex;
        $ex = null;
        $sanitized = $this->sanitize($original);
        $message = $sanitized === null ? $this->redactor->redact($original->getMessage()) : $sanitized->getMessage();
        $previous = $this->chainPrevious ? $sanitized : null;
        $code = (int)$original->getCode();
        $original = null;

        return new RequestException($messagePrefix . $message, $code, $previous);
    }

    /**
     * Returns a copy of an HTTP client exception that does not carry the access token: the credential headers
     * are removed from its request, and the token is redacted from the request URL, the message, and the
     * response body. The message of an HTTP error response is rebuilt from the redacted body, because the
     * client's message summarizes the raw body and its truncation can cut the token.
     * @param Throwable $ex exception raised by the HTTP client
     * @param string|null $redactedBody response body that was already read and redacted, or null to read it
     * @return Throwable|null copy without the token, or null when the exception cannot be copied
     */
    private function sanitize(Throwable $ex, ?string $redactedBody = null): ?Throwable
    {
        // PHP records the current values of the parameters in the trace of the copy created below this frame,
        // so the client's exception, whose request carries the token, is moved out of them
        $clientException = $ex;
        $ex = null;
        // A request exception first: one that also reports itself as a network failure may carry a response
        if ($clientException instanceof GuzzleRequestException) {
            $request = $this->withoutCredentials($clientException->getRequest());
            $context = $this->redactor->redactContext(GuzzleCompat::handlerContext($clientException));
            $original = GuzzleCompat::responseOf($clientException);
            if ($original === null) {
                return GuzzleCompat::copy($clientException, $this->redactor->redact($clientException->getMessage()), $request, null, $context);
            }
            $response = $this->withoutToken($original, $redactedBody);
            $message = $clientException instanceof BadResponseException && $response->getStatusCode() >= 400
                ? GuzzleRequestException::create($request, $response)->getMessage()
                : $this->redactor->redact($this->withRedactedBodySummary($clientException->getMessage(), $original, $response));

            return GuzzleCompat::copy($clientException, $message, $request, $response, $context);
        }
        if ($clientException instanceof TransferException && $clientException instanceof NetworkExceptionInterface) {
            // A connection or network failure without a response
            return GuzzleCompat::copy(
                $clientException,
                $this->redactor->redact($clientException->getMessage()),
                $this->withoutCredentials($clientException->getRequest()),
                null,
                $this->redactor->redactContext(GuzzleCompat::handlerContext($clientException))
            );
        }

        // Other exceptions cannot be copied, and their own previous exceptions may carry the token
        return null;
    }

    /**
     * Replaces the summary of the raw response body that Guzzle puts in a message with the summary of the
     * redacted body, because the raw summary is truncated and can end in the middle of the token.
     * @param string $message message of the HTTP client exception
     * @param ResponseInterface $original response received by the HTTP client
     * @param ResponseInterface $copy response with the redacted body
     * @return string message with the redacted body summary
     */
    private function withRedactedBodySummary(string $message, ResponseInterface $original, ResponseInterface $copy): string
    {
        try {
            $summary = Message::bodySummary($original);
        } catch (\RuntimeException $readError) {
            return $message;
        }
        if ($summary === null || $summary === '') {
            return $message;
        }
        $redactedSummary = Message::bodySummary($copy) ?? '';
        // Guzzle 8 escapes control characters of the summary in its messages; a custom client may not
        $message = str_replace(
            GuzzleCompat::escapeForMessage($summary),
            GuzzleCompat::escapeForMessage($redactedSummary),
            $message
        );

        return str_replace($summary, $redactedSummary, $message);
    }

    /**
     * Removes the access token from a response: it is redacted from the header values and the body.
     * @param ResponseInterface $response response received by the HTTP client
     * @param string|null $redactedBody body that was already read and redacted, or null to read it
     * @return ResponseInterface response without the access token
     */
    private function withoutToken(ResponseInterface $response, ?string $redactedBody): ResponseInterface
    {
        $redactedBody ??= $this->readRedactedBody($response);
        foreach ($response->getHeaders() as $name => $values) {
            $response = $response->withHeader($name, array_map(fn (string $value): string => $this->redactor->redact($value), $values));
        }

        return $response->withBody(Psr7Utils::streamFor($redactedBody));
    }

    /**
     * Reads and redacts a response body for a copied exception. An unreadable body, or one that is unknown
     * in size or too large, is left out, e.g. the partial body of a large download that failed.
     * @param ResponseInterface $response response received by the HTTP client
     * @return string redacted body, or an empty string when it is left out
     */
    private function readRedactedBody(ResponseInterface $response): string
    {
        $body = $response->getBody();
        try {
            if (!$body->isReadable() || ($body->getSize() ?? PHP_INT_MAX) > self::COPIED_BODY_LIMIT) {
                return '';
            }

            return $this->redactor->redact((string)$body);
        } catch (\RuntimeException $ex) {
            return '';
        }
    }

    /**
     * Removes the access token from a request: the credential headers and the token in the URL.
     * @param RequestInterface $request request sent by the HTTP client
     * @return RequestInterface request without the access token
     */
    private function withoutCredentials(RequestInterface $request): RequestInterface
    {
        return $request
            ->withoutHeader('X-AUTH-TOKEN')
            ->withoutHeader('Authorization')
            ->withUri(new Uri($this->redactor->redact((string)$request->getUri())), true);
    }
}
