<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Http\Internal;

use Daktela\DaktelaV6\Exception\RateLimitException;
use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\Http\GuzzleCompat;
use Daktela\DaktelaV6\Http\RateLimitConfig;
use Daktela\DaktelaV6\Http\RetryConfig;
use Daktela\DaktelaV6\Response\Response;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends one request to the API and applies the retry and rate-limit settings.
 * @internal
 */
final class Transport
{
    /** @var string[] HTTP methods that can be repeated without changing the result */
    private const IDEMPOTENT_METHODS = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'];

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestBuilder $requests,
        private readonly ResponseParser $responses,
        private readonly ExceptionFactory $exceptions,
        private readonly string $baseUrl,
        private readonly ?RetryConfig $retryConfig,
        private readonly ?RateLimitConfig $rateLimitConfig,
        private readonly ?LoggerInterface $logger
    ) {
    }

    /**
     * Sends a request to the API, applying the retry and rate-limit settings.
     * @param string $method HTTP method
     * @param string $apiEndpoint API endpoint
     * @param array $queryParams query parameters
     * @param array|null $data request payload, or null when none
     * @param bool $authenticate whether to send the access token; false for a login or token refresh request,
     *     whose payload is built from the login session
     * @return Response the resulting response of the request sent
     * @throws RequestException request exception with details
     */
    public function send(
        string $method,
        string $apiEndpoint,
        array $queryParams,
        #[\SensitiveParameter] ?array $data,
        bool $authenticate
    ): Response {
        $request = $this->requests->build($method, $apiEndpoint, $queryParams, $data, $authenticate);
        // Login and refresh payloads hold credentials, which a 307/308 redirect would send to its target
        $sendOptions = $authenticate ? [] : ['allow_redirects' => false];

        $retryCount = $this->retryConfig?->getMaxRetries() ?? 0;
        $rateLimitRetryCount = $this->rateLimitConfig?->shouldAutoRetry() ? max(1, $retryCount) : 0;
        $maxAttempts = 1 + max($retryCount, $rateLimitRetryCount);
        $applyRetryDelay = false;
        // buildRequest() upper-cases the method
        $canRetryAfterSend = in_array($request->getMethod(), self::IDEMPOTENT_METHODS, true)
            || ($this->retryConfig?->shouldRetryNonIdempotentRequests() ?? false);

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            // Apply exponential backoff for transport/status retries. Rate-limit
            // retries already wait according to Retry-After and must not wait twice.
            if ($applyRetryDelay && $this->retryConfig !== null) {
                $delayMs = $this->retryConfig->getDelayForAttempt($attempt - 1);
                $this->logger?->info('Retrying request', [
                    'attempt' => $attempt + 1,
                    'delay_ms' => $delayMs,
                    'endpoint' => $apiEndpoint,
                ]);
                usleep(max(0, $delayMs) * 1000);
            }
            $applyRetryDelay = false;

            $this->logger?->debug('Sending API request', [
                'method' => $method,
                'endpoint' => $apiEndpoint,
                'has_body' => !$authenticate || $data !== null,
                'attempt' => $attempt + 1,
            ]);

            $httpException = null;
            try {
                $httpResponse = $this->client->send($request, $sendOptions);
            } catch (BadResponseException $ex) {
                // Guzzle throws for 4xx/5xx by default. Keep the exception so
                // non-retryable errors retain their historical behavior, but
                // inspect its response before deciding whether to retry.
                $httpException = $ex;
                $httpResponse = $ex->getResponse();
            } catch (NetworkExceptionInterface | ResponseTransferException $ex) {
                // No response, or one that broke off after its headers: the connection failed, timed out, or
                // was reset. Guzzle 7 reports a failed connection and a timeout as ConnectException, and a reset
                // or a truncated body as a plain RequestException, which is not retried; Guzzle 8 reports them as
                // NetworkException, or as ResponseTransferException once the headers were received.
                // The message of the wrapped exception, as a broken-off response may be summarized in the message
                $exception = $this->exceptions->wrap($ex);
                $this->logger?->warning('Connection error', [
                    'endpoint' => $apiEndpoint,
                    'error' => $exception->getMessage(),
                ]);

                // A timeout may happen after the server received the request, so only
                // idempotent requests (or explicitly allowed ones) are repeated then.
                if (
                    $this->retryConfig === null
                    || !$this->retryConfig->shouldRetryOnConnectionError()
                    || (!$canRetryAfterSend && !$this->failedBeforeSend($ex, $request))
                ) {
                    throw $exception;
                }

                if ($attempt >= $maxAttempts - 1) {
                    throw $this->exceptions->wrap($ex, 'Max retries exceeded: ');
                }
                $applyRetryDelay = true;
                continue;
            } catch (Throwable $ex) {
                // Any other client exception, e.g. an invalid redirect URL or one of a custom HTTP client, and
                // errors such as a TypeError of a custom middleware, whose trace holds the request with the token
                $exception = $this->exceptions->wrap($ex);
                $this->logger?->error('API request failed', [
                    'method' => $method,
                    'endpoint' => $apiEndpoint,
                    'error' => $exception->getMessage(),
                    'code' => $ex->getCode(),
                    'exception' => get_class($ex),
                ]);
                throw $exception;
            }

            $statusCode = $httpResponse->getStatusCode();

            if ($statusCode === 429) {
                $this->handleRateLimit(
                    $httpResponse->getHeaderLine('Retry-After') ?: null,
                    $apiEndpoint,
                    $attempt < $maxAttempts - 1
                );
                continue;
            }

            if (
                $this->retryConfig !== null
                && $canRetryAfterSend
                && $attempt < $maxAttempts - 1
                && $this->retryConfig->isRetryableStatus($statusCode)
            ) {
                $this->logger?->warning('Retryable status code received', [
                    'status' => $statusCode,
                    'endpoint' => $apiEndpoint,
                ]);
                $applyRetryDelay = true;
                continue;
            }

            if ($httpException !== null) {
                $exception = $this->exceptions->fromHttpError($httpException, $httpResponse);
                $this->logger?->error('API request failed', [
                    'method' => $method,
                    'endpoint' => $apiEndpoint,
                    'error' => $exception->getMessage(),
                    'code' => $httpException->getCode(),
                ]);
                throw $exception;
            }

            return $this->responses->parse($httpResponse);
        }

        // Not reached: the last attempt returns or throws
        throw new RequestException('Max retries exceeded: Unknown error');
    }

    /**
     * Checks whether a network failure happened before the request was sent to the server, so that repeating it
     * cannot duplicate a server-side operation. A redirect hop that could not connect does not count: the server
     * received the request it redirected, e.g. a POST it answered with 303 See Other after creating a record.
     * @param Throwable $ex network exception raised by the HTTP client
     * @param RequestInterface $request request the connector sent
     * @return bool true when the request was not sent
     */
    private function failedBeforeSend(Throwable $ex, RequestInterface $request): bool
    {
        if (!$ex instanceof NetworkExceptionInterface || !GuzzleCompat::failedToConnect($ex)) {
            return false;
        }
        // A redirect hop has another method, URI, or origin. The HTTP client resolves the URI of the request
        // against its base URI, which removes dot segments from the path; a URI without a host was not resolved
        // (e.g. by a mock handler), so its origin is not compared.
        $failed = $ex->getRequest()->getUri();
        $sent = $request->getUri();
        if ($failed->getHost() !== '') {
            try {
                $instance = new Uri((string)InstanceUrl::normalize($this->baseUrl));
            } catch (\InvalidArgumentException $invalidUrl) {
                // An instance URL Guzzle cannot parse, used with a custom client: the origin is unknown
                return false;
            }
            if (InstanceUrl::leavesInstance($instance, $failed)) {
                return false;
            }
        }

        return $ex->getRequest()->getMethod() === $request->getMethod()
            && UriResolver::removeDotSegments($failed->getPath()) === UriResolver::removeDotSegments($sent->getPath())
            && $failed->getQuery() === $sent->getQuery();
    }

    /**
     * Handle rate limit response (HTTP 429).
     *
     * @param string|null $retryAfterHeader Retry-After header of the response, or null; the response itself is
     *     not passed, as the trace of the exception thrown here records the arguments
     * @param string $endpoint API endpoint
     * @param bool $canRetry Whether another request attempt is available
     * @return void
     * @throws RateLimitException If auto-retry is disabled or wait time exceeds maximum
     */
    private function handleRateLimit(
        ?string $retryAfterHeader,
        string $endpoint,
        bool $canRetry
    ): void {
        $waitSeconds = ($this->rateLimitConfig ?? new RateLimitConfig())->parseRetryAfter($retryAfterHeader);

        $this->logger?->warning('Rate limit hit', [
            'endpoint' => $endpoint,
            'retry_after_seconds' => $waitSeconds,
        ]);

        // If no rate limit config or auto-retry disabled, throw exception
        if (
            $this->rateLimitConfig === null
            || !$this->rateLimitConfig->shouldAutoRetry()
            || !$canRetry
        ) {
            throw new RateLimitException($waitSeconds);
        }

        // If wait time exceeds maximum, throw exception
        if ($waitSeconds > $this->rateLimitConfig->getMaxWaitSeconds()) {
            $this->logger?->error('Rate limit wait time exceeds maximum', [
                'wait_seconds' => $waitSeconds,
                'max_wait_seconds' => $this->rateLimitConfig->getMaxWaitSeconds(),
            ]);
            throw new RateLimitException($waitSeconds);
        }

        // Wait and signal to retry
        $this->logger?->info('Waiting for rate limit reset', ['seconds' => $waitSeconds]);
        sleep(max(0, $waitSeconds));
    }
}
