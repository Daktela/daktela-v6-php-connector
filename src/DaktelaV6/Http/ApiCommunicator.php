<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Http;

use Daktela\DaktelaV6\Exception\RateLimitException;
use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\Log\NullLogger;
use Daktela\DaktelaV6\Response\Response;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Class ApiCommunicator is a transport class of the Daktela V6 communication package and
 * performs the main HTTP operations necessary to perform actions onto Daktela V6 API.
 * @package Daktela\DaktelaV6\Http
 */
class ApiCommunicator
{
    /** @var int Authentication method using HTTP header X-AUTH-TOKEN */
    const AUTHENTICATION_METHOD_HEADER = 1;
    /** @var int Authentication method using query parameter accessToken */
    const AUTHENTICATION_METHOD_QUERY = 2;
    
    /** @var string Constant defining the base API URL */
    private const API_NAMESPACE = "/api/v6/";
    /** @var string Constant defining the User-Agent of the HTTP requests */
    private const USER_AGENT = "daktela-v6-php-connector";
    /** @var array static variable containing all singleton instances of the transport class */
    private static $singletons = [];
    /** @var string URL of the Daktela instance */
    private $baseUrl;
    /** @var string Access token used for communicating with Daktela REST API */
    private $accessToken;
    /** @var float Timeout for HTTP request sent to API */
    private $requestTimeout = 2.0;
    /** @var int The authentication method to use */
    private $authenticationMethod = self::AUTHENTICATION_METHOD_HEADER;
    /** @var bool Whether SSL certificate should be verified */
    private bool $verifySsl = true;
    /** @var string|null Custom User-Agent suffix to append */
    private ?string $userAgentSuffix = null;
    /** @var LoggerInterface Logger for debugging API calls */
    private LoggerInterface $logger;
    /** @var ClientInterface|null Custom HTTP client for requests */
    private ?ClientInterface $httpClient = null;
    /** @var RetryConfig|null Retry configuration */
    private ?RetryConfig $retryConfig = null;
    /** @var RateLimitConfig|null Rate limit configuration */
    private ?RateLimitConfig $rateLimitConfig = null;

    /**
     * ApiCommunicator constructor.
     * @param string $baseUrl URL of the Daktela instance
     * @param string $accessToken access token of user used for connecting to Daktela V6
     */
    public function __construct(string $baseUrl, string $accessToken)
    {
        $this->baseUrl = $baseUrl;
        $this->accessToken = $accessToken;
        $this->logger = new NullLogger();
    }

    /**
     * Static method for using ApiCommunicator client connector as singleton.
     * @param string $baseUrl URL of the Daktela instance
     * @param string $accessToken access token of user used for connecting to Daktela V6
     * @return ApiCommunicator instance of the transport class
     */
    public static function getInstance(string $baseUrl, string $accessToken): self
    {
        $key = md5($baseUrl . $accessToken);
        if (!isset(self::$singletons[$key])) {
            self::$singletons[$key] = new ApiCommunicator($baseUrl, $accessToken);
        }

        return self::$singletons[$key];
    }

    /**
     * Method for sending the requested data to Daktela API using HTTP client.
     * @param string $method requested HTTP method for the request (GET/POST/PUT/DELETE)
     * @param string $apiEndpoint requested API endpoint based on the Daktela V6 API documentation
     * @param array $queryParams query parameters to be sent as part of the URL
     * @param array|null $data collection of data to be sent as request payload (or null when none)
     * @return Response the resulting response of the request sent
     * @throws RequestException request exception with details
     */
    public function sendRequest(
        string $method,
        string $apiEndpoint,
        array $queryParams = [],
        ?array $data = null
    ): Response {
        $client = $this->httpClient ?? $this->createDefaultClient();
        $request = $this->buildRequest($method, $apiEndpoint, $queryParams, $data);

        $maxAttempts = 1 + ($this->retryConfig?->getMaxRetries() ?? 0);
        $lastException = null;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            // Apply delay for retries (not on first attempt)
            if ($attempt > 0) {
                $delayMs = $this->retryConfig->getDelayForAttempt($attempt - 1);
                $this->logger->info('Retrying request', [
                    'attempt' => $attempt + 1,
                    'delay_ms' => $delayMs,
                    'endpoint' => $apiEndpoint,
                ]);
                usleep($delayMs * 1000);
            }

            $this->logger->debug('Sending API request', [
                'method' => $method,
                'endpoint' => $apiEndpoint,
                'has_body' => !is_null($data),
                'attempt' => $attempt + 1,
            ]);

            try {
                $httpResponse = $client->send($request);
                $statusCode = $httpResponse->getStatusCode();

                // Handle rate limiting (429)
                if ($statusCode === 429) {
                    $response = $this->handleRateLimit($httpResponse, $method, $apiEndpoint);
                    if ($response === null) {
                        // Rate limit handled, retry
                        continue;
                    }
                    // Rate limit exception thrown or returned response
                    return $response;
                }

                // Check if we should retry based on status code
                if ($this->retryConfig !== null
                    && $attempt < $maxAttempts - 1
                    && $this->retryConfig->isRetryableStatus($statusCode)
                ) {
                    $this->logger->warning('Retryable status code received', [
                        'status' => $statusCode,
                        'endpoint' => $apiEndpoint,
                    ]);
                    continue;
                }

                return $this->parseResponse($httpResponse);

            } catch (ConnectException $ex) {
                $lastException = $ex;
                $this->logger->warning('Connection error', [
                    'endpoint' => $apiEndpoint,
                    'error' => $ex->getMessage(),
                ]);

                if ($this->retryConfig === null || !$this->retryConfig->shouldRetryOnConnectionError()) {
                    throw new RequestException($ex->getMessage(), $ex->getCode(), $ex);
                }

                if ($attempt >= $maxAttempts - 1) {
                    throw new RequestException(
                        'Max retries exceeded: ' . $ex->getMessage(),
                        $ex->getCode(),
                        $ex
                    );
                }
                // Will retry on next iteration

            } catch (GuzzleException $ex) {
                $this->logger->error('API request failed', [
                    'method' => $method,
                    'endpoint' => $apiEndpoint,
                    'error' => $ex->getMessage(),
                    'code' => $ex->getCode(),
                ]);
                throw new RequestException($ex->getMessage(), $ex->getCode(), $ex);
            }
        }

        // If we exit the loop without returning, throw exception
        throw new RequestException(
            'Max retries exceeded: ' . ($lastException?->getMessage() ?? 'Unknown error'),
            $lastException?->getCode() ?? 0,
            $lastException
        );
    }

    /**
     * Handle rate limit response (HTTP 429).
     *
     * @param ResponseInterface $response The HTTP response
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @return Response|null Response if should not retry, null if should retry
     * @throws RateLimitException If auto-retry is disabled or wait time exceeds maximum
     */
    private function handleRateLimit(ResponseInterface $response, string $method, string $endpoint): ?Response
    {
        $retryAfterHeader = $response->getHeaderLine('Retry-After') ?: null;
        $waitSeconds = $this->rateLimitConfig?->parseRetryAfter($retryAfterHeader)
            ?? ($this->rateLimitConfig?->getDefaultWaitSeconds() ?? 5);

        $this->logger->warning('Rate limit hit', [
            'endpoint' => $endpoint,
            'retry_after_seconds' => $waitSeconds,
        ]);

        // If no rate limit config or auto-retry disabled, throw exception
        if ($this->rateLimitConfig === null || !$this->rateLimitConfig->shouldAutoRetry()) {
            throw new RateLimitException($waitSeconds);
        }

        // If wait time exceeds maximum, throw exception
        if ($waitSeconds > $this->rateLimitConfig->getMaxWaitSeconds()) {
            $this->logger->error('Rate limit wait time exceeds maximum', [
                'wait_seconds' => $waitSeconds,
                'max_wait_seconds' => $this->rateLimitConfig->getMaxWaitSeconds(),
            ]);
            throw new RateLimitException($waitSeconds);
        }

        // Wait and signal to retry
        $this->logger->info('Waiting for rate limit reset', ['seconds' => $waitSeconds]);
        sleep($waitSeconds);
        return null;
    }

    /**
     * Build the PSR-7 request object.
     *
     * @param string $method HTTP method
     * @param string $apiEndpoint API endpoint
     * @param array $queryParams Query parameters
     * @param array|null $data Request body data
     * @return Request PSR-7 request object
     */
    private function buildRequest(
        string $method,
        string $apiEndpoint,
        array $queryParams,
        ?array $data
    ): Request {
        $userAgent = self::USER_AGENT;
        if ($this->userAgentSuffix !== null) {
            $userAgent .= ' ' . $this->userAgentSuffix;
        }
        $headers = ["User-Agent" => $userAgent, "Content-Type" => "application/json"];

        if ($this->authenticationMethod === self::AUTHENTICATION_METHOD_QUERY) {
            $queryParams['accessToken'] = $this->accessToken;
        } else {
            $headers['X-AUTH-TOKEN'] = $this->accessToken;
        }

        $requestUri = self::API_NAMESPACE . lcfirst($apiEndpoint) . ".json?" . http_build_query($queryParams);
        $body = $data !== null ? Utils::jsonEncode($data) : null;

        return new Request($method, $requestUri, $headers, $body);
    }

    /**
     * Parse HTTP response into Response object.
     *
     * @param ResponseInterface $httpResponse HTTP response
     * @return Response Parsed response
     * @throws RequestException If JSON parsing fails
     */
    private function parseResponse(ResponseInterface $httpResponse): Response
    {
        $responseBody = $httpResponse->getBody()->getContents();
        if (mb_strlen($responseBody)) {
            try {
                $responseBody = Utils::jsonDecode($responseBody);
            } catch (InvalidArgumentException $ex) {
                $this->logger->error('Failed to parse API response', [
                    'error' => $ex->getMessage(),
                ]);
                throw new RequestException($ex->getMessage(), $ex->getCode(), $ex);
            }
        }

        if (!isset($responseBody->result)) {
            $this->logger->debug('API response received (no result)', [
                'status' => $httpResponse->getStatusCode(),
            ]);
            return new Response(null, 0, [], $httpResponse->getStatusCode());
        }

        $responseData = $responseBody->result->data ?? ($responseBody->result ?? null);
        $total = $responseBody->result->total ?? 1;
        $errors = !isset($responseBody->error) ? [] : $responseBody->error;

        $this->logger->debug('API response received', [
            'status' => $httpResponse->getStatusCode(),
            'total' => $total,
            'has_errors' => !empty($errors),
        ]);

        return new Response($responseData, $total, $errors, $httpResponse->getStatusCode());
    }

    /**
     * Creates the default Guzzle HTTP client with configured settings.
     *
     * @return ClientInterface
     */
    private function createDefaultClient(): ClientInterface
    {
        return new Client([
            'base_uri' => self::normalizeUrl($this->baseUrl),
            'timeout' => $this->requestTimeout,
            'verify' => $this->verifySsl,
        ]);
    }

    /**
     * The HTTP request timeout that should be used when communicating with the associated API.
     * @param float $requestTimeout Timeout of the HTTP request
     * @noinspection PhpPhpUnused
     */
    public function setRequestTimeout(float $requestTimeout): void
    {
        $this->requestTimeout = $requestTimeout;
    }

    /**
     * Method for normalizing URL into one standard form the API transport class can use as part of the HTTP request.
     * @param string|null $url URL to be normalized
     * @return string|null normalized URL
     */
    public static function normalizeUrl(?string $url): ?string
    {
        if (is_null($url)) {
            return null;
        }

        /** @noinspection HttpUrlsUsage */
        if ((mb_substr($url, 0, 7) != "http://") && (mb_substr($url, 0, 8) != "https://")) {
            $url = "https://" . $url;
        }
        if (mb_substr($url, -1) == "/") {
            $url = mb_substr($url, 0, -1);
        }

        return $url;
    }

    /**
     * Sets the authentication method to use.
     * @param int $authenticationMethod Authentication method to use
     * @throws RequestException if the authentication method is invalid
     */
    public function setAuthenticationMethod(int $authenticationMethod): void
    {
        if ($authenticationMethod !== self::AUTHENTICATION_METHOD_HEADER && $authenticationMethod !== self::AUTHENTICATION_METHOD_QUERY) {
            throw new RequestException('Invalid authentication method');
        }
        $this->authenticationMethod = $authenticationMethod;
    }

    /**
     * Sets whether SSL certificates should be verified.
     * WARNING: Disabling SSL verification is insecure and should only be used for development/testing.
     *
     * @param bool $verify Whether to verify SSL certificates
     * @return $this
     */
    public function setVerifySsl(bool $verify): self
    {
        $this->verifySsl = $verify;
        return $this;
    }

    /**
     * Sets a custom suffix to append to the User-Agent header.
     * The final User-Agent will be: "daktela-v6-php-connector YourApp/1.0"
     *
     * @param string|null $suffix Custom suffix (e.g., "MyApp/1.0"), or null to reset
     * @return $this
     */
    public function setUserAgentSuffix(?string $suffix): self
    {
        $this->userAgentSuffix = $suffix;
        return $this;
    }

    /**
     * Sets the logger instance for debugging API calls.
     *
     * @param LoggerInterface $logger PSR-3 compatible logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Sets a custom HTTP client for making requests.
     * Useful for testing with mock clients or for advanced configuration (proxies, middleware, etc.).
     *
     * Note: When a custom client is set, the timeout and SSL verification
     * settings from this class are ignored - configure them on your client instead.
     *
     * @param ClientInterface|null $client Guzzle-compatible HTTP client, or null to use default
     * @return $this
     */
    public function setHttpClient(?ClientInterface $client): self
    {
        $this->httpClient = $client;
        return $this;
    }

    /**
     * Sets the retry configuration for failed requests.
     *
     * @param RetryConfig|null $config Retry configuration, or null to disable retries
     * @return $this
     */
    public function setRetryConfig(?RetryConfig $config): self
    {
        $this->retryConfig = $config;
        return $this;
    }

    /**
     * Sets the rate limit handling configuration.
     *
     * @param RateLimitConfig|null $config Rate limit config, or null to throw exception on 429
     * @return $this
     */
    public function setRateLimitConfig(?RateLimitConfig $config): self
    {
        $this->rateLimitConfig = $config;
        return $this;
    }

    /**
     * Performs a health check to verify API connectivity.
     * Uses the whoim endpoint which is accessible to all authenticated users.
     *
     * @return bool True if the API is reachable and responding
     */
    public function ping(): bool
    {
        try {
            $response = $this->sendRequest('GET', 'whoim');
            return $response->isSuccess();
        } catch (RequestException $ex) {
            // Connection errors indicate ping failure
            if (str_contains($ex->getMessage(), 'cURL error')
                || str_contains($ex->getMessage(), 'Connection')
                || str_contains($ex->getMessage(), 'Could not resolve')
            ) {
                return false;
            }
            // Other errors (auth, etc.) - API is reachable but request failed
            // Still consider this as "not healthy" for ping purposes
            return false;
        }
    }

    /**
     * Performs a health check and returns detailed information.
     * Uses the whoim endpoint which is accessible to all authenticated users.
     *
     * @return array{healthy: bool, latency_ms: float, status_code?: int, error?: string}
     */
    public function healthCheck(): array
    {
        $startTime = microtime(true);

        try {
            $response = $this->sendRequest('GET', 'whoim');
            $latencyMs = (microtime(true) - $startTime) * 1000;

            return [
                'healthy' => $response->isSuccess(),
                'latency_ms' => round($latencyMs, 2),
                'status_code' => $response->getHttpStatus(),
            ];
        } catch (RequestException $ex) {
            $latencyMs = (microtime(true) - $startTime) * 1000;

            return [
                'healthy' => false,
                'latency_ms' => round($latencyMs, 2),
                'error' => $ex->getMessage(),
            ];
        }
    }
}
