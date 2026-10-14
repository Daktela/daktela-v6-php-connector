<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Http;

use Daktela\DaktelaV6\Auth\LoginSession;
use Daktela\DaktelaV6\Auth\TokenSet;
use Daktela\DaktelaV6\Auth\TokenStore;
use Daktela\DaktelaV6\Exception\NotFoundException;
use Daktela\DaktelaV6\Exception\RateLimitException;
use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\Response\Response;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils as Psr7Utils;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Class ApiCommunicator is a transport class of the Daktela V6 communication package and
 * performs the main HTTP operations necessary to perform actions onto Daktela V6 API.
 * @package Daktela\DaktelaV6\Http
 */
class ApiCommunicator
{
    /** @var int Authentication method using HTTP header X-AUTH-TOKEN (default; legacy in the Daktela API docs) */
    const AUTHENTICATION_METHOD_HEADER = 1;
    /** @var int Authentication method using query parameter accessToken */
    const AUTHENTICATION_METHOD_QUERY = 2;
    /** @var int Authentication method using HTTP header Authorization: Bearer (Daktela 31.37.0, 32.36.0, 33.14.0 and later) */
    const AUTHENTICATION_METHOD_BEARER = 3;
    
    /** @var string Constant defining the base API URL */
    private const API_NAMESPACE = "/api/v6/";
    /** @var string Constant defining the User-Agent of the HTTP requests */
    private const USER_AGENT = "daktela-v6-php-connector";
    /** @var string[] HTTP methods that can be repeated without changing the result */
    private const IDEMPOTENT_METHODS = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'];
    /** @var int[] cURL error numbers raised before the request is sent (resolve/connect/TLS handshake failures) */
    private const CURL_ERRORS_BEFORE_SEND = [6, 7, 35];
    /** @var string[] Guzzle exception classes a copied exception can have, most specific first */
    private const GUZZLE_REQUEST_EXCEPTIONS = [
        ClientException::class,
        ServerException::class,
        BadResponseException::class,
        TooManyRedirectsException::class,
        GuzzleRequestException::class,
    ];
    /** @var int Largest response body, in bytes, copied into an exception chained as the previous exception */
    private const COPIED_BODY_LIMIT = 1048576;
    /** @var int Deepest nesting of arrays kept in the handler context of a copied exception */
    private const CONTEXT_DEPTH_LIMIT = 3;
    /** @var string Request option shared by the hops of a redirected request; records that one left the instance */
    private const LEFT_INSTANCE_OPTION = 'daktela_left_instance';
    /** @var int Seconds before the expiry of a login session's access token when it is refreshed */
    private const TOKEN_REFRESH_MARGIN = 60;
    /** @var string Health check error used when the API answers without identifying the token's user */
    private const TOKEN_NOT_ACCEPTED_ERROR = 'The access token was not accepted: the API did not identify a user.';
    /** @var string Health check error used for an HTTP error response that carries no API error */
    private const HTTP_ERROR_WITHOUT_MESSAGE = 'The API answered HTTP %d without an error message.';
    /** @var string Health check error used for a successful response that is not a whoim response */
    private const UNEXPECTED_RESPONSE_ERROR = 'The API returned an unexpected response without user information; check the instance URL.';
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
    /** @var LoggerInterface|null Logger for debugging API calls */
    private ?LoggerInterface $logger = null;
    /** @var ClientInterface|null Custom HTTP client for requests */
    private ?ClientInterface $httpClient = null;
    /** @var RetryConfig|null Retry configuration */
    private ?RetryConfig $retryConfig = null;
    /** @var RateLimitConfig|null Rate limit configuration */
    private ?RateLimitConfig $rateLimitConfig = null;
    /** @var \stdClass|null key of the login session of this object in $loginSessions */
    private ?\stdClass $sessionKey = null;
    /**
     * @var \WeakMap<\stdClass, LoginSession>|null login sessions, kept outside the objects' properties so that
     *     var_export(), serialize() and array casts cannot reveal their credentials and tokens. A clone shares
     *     the session of the original; an unserialized copy has none.
     */
    private static ?\WeakMap $loginSessions = null;

    /**
     * ApiCommunicator constructor.
     * @param string $baseUrl URL of the Daktela instance
     * @param string $accessToken access token of user used for connecting to Daktela V6
     */
    public function __construct(string $baseUrl, string $accessToken)
    {
        $this->baseUrl = $baseUrl;
        $this->accessToken = $accessToken;
    }

    /**
     * Static method for using ApiCommunicator client connector as singleton.
     * Every caller with the same URL and access token gets the same object, so its settings
     * (authentication method, timeout, logger, retries, ...) are shared. Create an instance with
     * `new ApiCommunicator()` for independent settings.
     * @param string $baseUrl URL of the Daktela instance
     * @param string $accessToken access token of user used for connecting to Daktela V6
     * @return ApiCommunicator instance of the transport class
     */
    public static function getInstance(string $baseUrl, string $accessToken): self
    {
        $key = hash('sha256', $baseUrl . "\0" . $accessToken);
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
        $this->prepareSession();

        try {
            return $this->dispatch($method, $apiEndpoint, $queryParams, $data, true);
        } catch (RequestException $ex) {
            if ($this->session()?->tokens === null || $ex->getHttpStatus() !== 401) {
                throw $ex;
            }
            // The server answers 401 before it processes a request, e.g. when it has ended the session,
            // so the request is sent once more after a refresh
            $this->refreshTokens();

            return $this->dispatch($method, $apiEndpoint, $queryParams, $data, true);
        }
    }

    /**
     * Starts a session by logging in with a username and password instead of using a static access token.
     * The access token of the session is short-lived; the connector refreshes it before it expires, and
     * logs in again when the session has ended. With a token store, the session is reused across processes
     * and no login is sent while the stored refresh token is valid. After the server rejects the
     * credentials, no further login is sent until login() is called again. Accounts with two-factor
     * authentication cannot log in this way; use a static API token for them.
     * @param string $username name of the Daktela user
     * @param string $password password of the Daktela user, kept in memory to log in again
     * @param TokenStore|null $tokenStore store that keeps the tokens between processes, or null
     * @throws RequestException when the login fails
     */
    public function login(string $username, #[\SensitiveParameter] string $password, ?TokenStore $tokenStore = null): void
    {
        $session = new LoginSession($username, $password, $tokenStore);
        // PHP records the current values of the parameters in the trace of every exception created below
        // this frame, so the password and the store (which holds tokens) are removed from them
        $password = '';
        $tokenStore = null;
        // A new key: a previous session must not survive a failed login
        $this->sessionKey = new \stdClass();
        self::$loginSessions ??= new \WeakMap();
        self::$loginSessions[$this->sessionKey] = $session;
        $this->accessToken = '';

        $stored = $this->loadStoredTokens();
        if ($stored !== null) {
            $this->useTokens($stored, false);
            return;
        }
        $this->loginWithCredentials();
    }

    /**
     * @return LoginSession|null login session of this object, or null when a static access token is used
     */
    private function session(): ?LoginSession
    {
        return $this->sessionKey === null ? null : (self::$loginSessions[$this->sessionKey] ?? null);
    }

    /**
     * @return string access token to authenticate requests with: the one of the login session, or the static one
     */
    private function currentAccessToken(): string
    {
        $session = $this->session();

        return $session === null ? $this->accessToken : ($session->tokens?->getAccessToken() ?? '');
    }

    /**
     * Prepares a login session for a request: logs in when the session has no tokens, and refreshes an
     * access token that is about to expire. A refresh that fails, e.g. on a network error, leaves the
     * current access token in use until it expires.
     * @throws RequestException when the session cannot authenticate the request
     */
    private function prepareSession(): void
    {
        $session = $this->session();
        if ($session === null) {
            return;
        }
        if ($session->tokens === null) {
            $this->loginWithCredentials();
            return;
        }
        if (!$session->refreshBeforeExpiry || !$session->tokens->accessTokenExpiresWithin(self::TOKEN_REFRESH_MARGIN, time())) {
            return;
        }
        try {
            $this->refreshTokens();
        } catch (RequestException $ex) {
            if (!$this->hasValidAccessToken()) {
                throw $ex;
            }
            $this->logger?->warning('Refreshing the access token failed; the current one is used until it expires', [
                'error' => $ex->getMessage(),
            ]);
        }
    }

    /**
     * @return bool whether the login session has an access token that has not expired by the local clock
     */
    private function hasValidAccessToken(): bool
    {
        $tokens = $this->session()?->tokens;

        return $tokens !== null && !$tokens->accessTokenExpiresWithin(0, time());
    }

    /**
     * Logs in with the credentials of the session.
     * @throws RequestException when the login fails or was rejected before
     */
    private function loginWithCredentials(): void
    {
        $session = $this->session();
        if ($session === null) {
            throw new RequestException('There is no login session; call login() first.');
        }
        if ($session->rejectedStatus !== null) {
            throw new RequestException(
                'The server rejected the login; call login() again with valid credentials.',
                0,
                null,
                $session->rejectedStatus
            );
        }
        try {
            $this->useTokens($this->requestTokens('login'), true);
        } catch (RequestException $ex) {
            if ($ex->getHttpStatus() === 400 || $ex->getHttpStatus() === 401) {
                // Repeating a rejected login could lock the account
                $session->rejectedStatus = $ex->getHttpStatus();
                $session->tokens = null;
            }
            throw $ex;
        }
        $this->saveTokens();
        $this->logger?->info('Logged in to the Daktela API', ['username' => $session->username]);
    }

    /**
     * Replaces the access token of the session with a new one; logs in again when the refresh is rejected,
     * e.g. because the session has ended.
     * @throws RequestException when both the refresh and the login fail
     */
    private function refreshTokens(): void
    {
        $tokens = $this->session()?->tokens;
        if ($tokens === null || $tokens->isRefreshTokenExpired(time())) {
            $this->loginWithCredentials();
            return;
        }
        try {
            $this->useTokens($this->requestTokens('refreshAccessToken'), true);
        } catch (RequestException $ex) {
            if ($ex->getHttpStatus() !== 400 && $ex->getHttpStatus() !== 401) {
                throw $ex;
            }
            $this->loginWithCredentials();
            return;
        }
        $this->saveTokens();
        $this->logger?->info('Refreshed the access token of the Daktela API');
    }

    /**
     * Sends a login or token refresh request, which carries its credentials in the payload instead of the
     * access token. The payload is built here from the session, so that no frame in an exception trace
     * has the credentials as an argument, and the exceptions are created again in this frame, without the
     * HTTP client's exception (its request contains the payload) and without the trace below.
     * @param string $apiEndpoint login or refreshAccessToken
     * @return TokenSet tokens returned by the API
     * @throws RequestException when the request fails or the response contains no tokens
     */
    private function requestTokens(string $apiEndpoint): TokenSet
    {
        $session = $this->session();
        $current = $session?->tokens;
        $payload = $apiEndpoint === 'login'
            ? [
                'username' => $session === null ? '' : $session->username,
                'password' => $session === null ? '' : $session->password,
                // the tokens are returned instead of being set as cookies
                'only_token' => 'true',
                // the connector refreshes the session itself, the server does not renew it implicitly
                'client_side_refresh' => 'true',
            ]
            : ['refresh_token' => $current === null ? '' : $current->getRefreshToken()];
        try {
            $response = $this->dispatch('POST', $apiEndpoint, [], $payload, false);
        } catch (RateLimitException $ex) {
            throw new RateLimitException($ex->getRetryAfterSeconds());
        } catch (RequestException $ex) {
            $status = $ex->getHttpStatus();
            // A successful response that could not be parsed may contain the new tokens
            $body = $status !== null && $status >= 300 ? $ex->getResponseBody() : null;
            if ($ex instanceof NotFoundException) {
                throw new NotFoundException($ex->getMessage(), null, $body, $ex->getApiErrors());
            }
            throw new RequestException($ex->getMessage(), $ex->getCode(), null, $status, $body, $ex->getApiErrors());
        } catch (\Throwable $ex) {
            // Anything else, e.g. from a logger, has frames with the payload as an argument
            throw new RequestException($this->redact($ex->getMessage()));
        }

        $result = $response->getData();
        $accessToken = is_object($result) ? ($result->accessToken ?? null) : null;
        $refreshToken = is_object($result) ? ($result->refreshToken ?? null) : null;
        if ($apiEndpoint !== 'login' && $current !== null && ($refreshToken === null || $refreshToken === '')) {
            // The server keeps the refresh token when it does not rotate it
            $refreshToken = $current->getRefreshToken();
        }
        if (!is_string($accessToken) || $accessToken === '' || !is_string($refreshToken) || $refreshToken === '') {
            throw new RequestException(
                sprintf(
                    'The %s response (HTTP %d) does not contain an access token and a refresh token.',
                    $apiEndpoint,
                    $response->getHttpStatus()
                ),
                0,
                null,
                $response->getHttpStatus()
            );
        }

        return TokenSet::fromTokens($accessToken, $refreshToken, $this->sessionOwner());
    }

    /**
     * @return string session the tokens of the login belong to, as `username@instance URL`
     */
    private function sessionOwner(): string
    {
        return ($this->session()->username ?? '') . '@' . self::normalizeUrl($this->baseUrl);
    }

    /**
     * Loads the tokens of this session from the token store. Tokens of another user or instance, expired
     * tokens, and a store that fails are ignored, and the connector logs in instead.
     * @return TokenSet|null usable stored tokens, or null
     */
    private function loadStoredTokens(): ?TokenSet
    {
        try {
            $stored = $this->session()?->tokenStore?->load();
        } catch (\Throwable $ex) {
            $this->logger?->warning('Loading the saved login session failed; logging in', [
                'error' => $this->redact($ex->getMessage()),
            ]);
            return null;
        }
        // A store shared by several users or instances must not resume the session of another one
        if ($stored === null || $stored->getOwner() !== $this->sessionOwner() || $stored->isRefreshTokenExpired(time())) {
            return null;
        }

        return $stored;
    }

    /**
     * Saves the tokens of the session to the token store. When the store fails, the session continues with
     * the tokens in memory. The tokens are read from the session rather than passed as an argument, so that
     * no frame of an exception escaping from here, e.g. from the logger, holds them.
     */
    private function saveTokens(): void
    {
        $session = $this->session();
        if ($session === null || $session->tokenStore === null || $session->tokens === null) {
            return;
        }
        try {
            $session->tokenStore->save($session->tokens);
        } catch (\Throwable $ex) {
            $this->logger?->warning('Saving the login session failed; it is kept in memory', [
                'error' => $this->redact($ex->getMessage()),
            ]);
        }
    }

    /**
     * @param TokenSet $tokens tokens of the session to authenticate the following requests with
     * @param bool $received whether the API has just issued the tokens
     */
    private function useTokens(TokenSet $tokens, bool $received): void
    {
        $session = $this->session();
        if ($session === null) {
            return;
        }
        if ($session->tokens !== null) {
            // Replaced tokens are still redacted, as later responses may repeat them
            $replaced = [$session->tokens->getAccessToken(), $session->tokens->getRefreshToken()];
            $session->retiredTokens = array_slice(array_values(array_unique(array_merge($replaced, $session->retiredTokens))), 0, 4);
        }
        $session->tokens = $tokens;
        // A token that looks expired as soon as it is issued means the local clock is ahead of the server:
        // refreshing it before every request would not help, so it is refreshed after a 401 instead
        $session->refreshBeforeExpiry = !$received || !$tokens->accessTokenExpiresWithin(self::TOKEN_REFRESH_MARGIN, time());
    }

    /**
     * Hides the access token from var_dump() and print_r(). The credentials and tokens of a login session
     * are not properties of this object at all.
     * @return array properties of the object with the access token replaced
     */
    public function __debugInfo(): array
    {
        $properties = get_object_vars($this);
        if (($properties['accessToken'] ?? '') !== '') {
            $properties['accessToken'] = '***';
        }

        return $properties;
    }

    /**
     * Sends a request to the API, applying the retry and rate-limit settings.
     * @param string $method HTTP method
     * @param string $apiEndpoint API endpoint
     * @param array $queryParams query parameters
     * @param array|null $data request payload, or null when none
     * @param bool $authenticate whether to send the access token
     * @return Response the resulting response of the request sent
     * @throws RequestException request exception with details
     */
    private function dispatch(
        string $method,
        string $apiEndpoint,
        array $queryParams,
        ?array $data,
        bool $authenticate
    ): Response {
        $client = $this->httpClient ?? $this->createDefaultClient();
        $request = $this->buildRequest($method, $apiEndpoint, $queryParams, $data, $authenticate);
        // Login and refresh payloads hold credentials, which a 307/308 redirect would send to its target
        $sendOptions = $authenticate ? [] : ['allow_redirects' => false];

        $retryCount = $this->retryConfig?->getMaxRetries() ?? 0;
        $rateLimitRetryCount = $this->rateLimitConfig?->shouldAutoRetry() ? max(1, $retryCount) : 0;
        $maxAttempts = 1 + max($retryCount, $rateLimitRetryCount);
        $lastException = null;
        $applyRetryDelay = false;
        $canRetryAfterSend = in_array(strtoupper($method), self::IDEMPOTENT_METHODS, true)
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
                usleep($delayMs * 1000);
            }
            $applyRetryDelay = false;

            $this->logger?->debug('Sending API request', [
                'method' => $method,
                'endpoint' => $apiEndpoint,
                'has_body' => !is_null($data),
                'attempt' => $attempt + 1,
            ]);

            $httpException = null;
            try {
                $httpResponse = $client->send($request, $sendOptions);
            } catch (BadResponseException $ex) {
                // Guzzle throws for 4xx/5xx by default. Keep the exception so
                // non-retryable errors retain their historical behavior, but
                // inspect its response before deciding whether to retry.
                $httpException = $ex;
                $httpResponse = $ex->getResponse();
            } catch (ConnectException $ex) {
                $lastException = $ex;
                $this->logger?->warning('Connection error', [
                    'endpoint' => $apiEndpoint,
                    'error' => $this->redact($ex->getMessage()),
                ]);

                // A timeout may happen after the server received the request, so only
                // idempotent requests (or explicitly allowed ones) are repeated then.
                if ($this->retryConfig === null
                    || !$this->retryConfig->shouldRetryOnConnectionError()
                    || (!$canRetryAfterSend && !$this->failedBeforeSend($ex))
                ) {
                    throw $this->wrapException($ex);
                }

                if ($attempt >= $maxAttempts - 1) {
                    throw $this->wrapException($ex, 'Max retries exceeded: ');
                }
                $applyRetryDelay = true;
                continue;

            } catch (GuzzleException | \Exception $ex) {
                // Any other client exception, e.g. an invalid redirect URL or one of a custom HTTP client
                $exception = $this->wrapException($ex);
                $this->logger?->error('API request failed', [
                    'method' => $method,
                    'endpoint' => $apiEndpoint,
                    'error' => $exception->getMessage(),
                    'code' => $ex->getCode(),
                ]);
                throw $exception;
            }

            $statusCode = $httpResponse->getStatusCode();

            if ($statusCode === 429) {
                $this->handleRateLimit(
                    $httpResponse,
                    $apiEndpoint,
                    $attempt < $maxAttempts - 1
                );
                continue;
            }

            if ($this->retryConfig !== null
                && $canRetryAfterSend
                && $attempt < $maxAttempts - 1
                && $this->retryConfig->isRetryableStatus($statusCode)
            ) {
                $lastException = $httpException;
                $this->logger?->warning('Retryable status code received', [
                    'status' => $statusCode,
                    'endpoint' => $apiEndpoint,
                ]);
                $applyRetryDelay = true;
                continue;
            }

            if ($httpException !== null) {
                $exception = $this->createHttpException($httpException, $httpResponse);
                $this->logger?->error('API request failed', [
                    'method' => $method,
                    'endpoint' => $apiEndpoint,
                    'error' => $exception->getMessage(),
                    'code' => $httpException->getCode(),
                ]);
                throw $exception;
            }

            return $this->parseResponse($httpResponse);
        }

        // If we exit the loop without returning, throw exception
        if ($lastException !== null) {
            throw $this->wrapException($lastException, 'Max retries exceeded: ');
        }
        throw new RequestException('Max retries exceeded: Unknown error');
    }

    /**
     * Checks whether a connection error happened before the request was sent to the server.
     * @param ConnectException $ex connection exception raised by the HTTP client
     * @return bool true when repeating the request cannot duplicate a server-side operation
     */
    private function failedBeforeSend(ConnectException $ex): bool
    {
        $errno = $ex->getHandlerContext()['errno'] ?? null;

        return is_int($errno) && in_array($errno, self::CURL_ERRORS_BEFORE_SEND, true);
    }

    /**
     * Converts an HTTP error response into the connector exception carrying the response details.
     * @param BadResponseException $ex exception raised by the HTTP client
     * @param ResponseInterface $response failed HTTP response
     * @return RequestException exception to be thrown
     */
    private function createHttpException(BadResponseException $ex, ResponseInterface $response): RequestException
    {
        try {
            $body = $this->redact((string)$response->getBody());
        } catch (\RuntimeException $readError) {
            // e.g. a detached or write-only body of a custom HTTP client
            $body = '';
        }
        $decoded = json_decode($body);
        $apiErrors = is_object($decoded) ? $this->redactValues($this->normalizeErrors($decoded->error ?? null)) : [];
        $sanitized = $this->sanitize($ex, $body);
        $message = $sanitized === null ? $this->redact($ex->getMessage()) : $sanitized->getMessage();
        $previous = $this->authenticationMethod === self::AUTHENTICATION_METHOD_QUERY ? null : $sanitized;

        if ($response->getStatusCode() === 404) {
            return new NotFoundException($message, $previous, $body, $apiErrors);
        }

        return new RequestException($message, $ex->getCode(), $previous, $response->getStatusCode(), $body, $apiErrors);
    }

    /**
     * Normalizes the `error` member of an API response into an array.
     * @param mixed $errors decoded `error` member
     * @return array list or map of errors
     */
    private function normalizeErrors(mixed $errors): array
    {
        if ($errors === null || $errors === '' || $errors === []) {
            return [];
        }
        if (is_array($errors)) {
            return $errors;
        }
        if (is_object($errors)) {
            // Only the top level is converted so that list entries keep their decoded shape
            return get_object_vars($errors);
        }

        return [$errors];
    }

    /**
     * Checks whether a whoim response identifies the user of the access token. The endpoint is public,
     * so a request whose token was not accepted still gets HTTP 200, with a null user.
     * @param mixed $data `result` member of the whoim response
     * @return bool true when the response contains a user
     */
    private function identifiesUser(mixed $data): bool
    {
        return is_object($data) && ($data->user ?? null) !== null;
    }

    /**
     * Explains why a whoim response that was received is not healthy.
     * @param Response $response whoim response that does not identify a user
     * @return string the API error, or a description of the response when the API reported none
     */
    private function describeUnhealthyResponse(Response $response): string
    {
        $apiError = $this->firstErrorMessage($response->getErrors());
        if ($apiError !== null) {
            // Only text received from the API can contain the token
            return $this->redact($apiError);
        }
        if (!$response->isSuccess()) {
            return sprintf(self::HTTP_ERROR_WITHOUT_MESSAGE, $response->getHttpStatus());
        }
        $data = $response->getData();

        // whoim always returns the user member; it is null when the token was not accepted
        return is_object($data) && property_exists($data, 'user') ? self::TOKEN_NOT_ACCEPTED_ERROR : self::UNEXPECTED_RESPONSE_ERROR;
    }

    /**
     * Returns the first error message reported by the API, searching nested lists and maps.
     * @param array $errors errors in the shape returned by normalizeErrors()
     * @return string|null first non-empty message, or null when there is none
     */
    private function firstErrorMessage(array $errors): ?string
    {
        foreach ($errors as $error) {
            if (is_object($error)) {
                $error = get_object_vars($error);
            }
            $message = is_array($error) ? $this->firstErrorMessage($error) : (is_string($error) ? trim($error) : null);
            if ($message !== null && $message !== '') {
                return $message;
            }
        }

        return null;
    }

    /**
     * Wraps an exception of the HTTP client in a RequestException without the access token. The chained
     * previous exception is a copy without the token (see sanitize()), because loggers and error trackers
     * serialize the whole exception chain. With query parameter authentication nothing is chained.
     * @param Throwable $ex exception raised by the HTTP client
     * @param string $messagePrefix text put before the message
     * @return RequestException exception to be thrown
     */
    private function wrapException(Throwable $ex, string $messagePrefix = ''): RequestException
    {
        $sanitized = $this->sanitize($ex);
        $message = $sanitized === null ? $this->redact($ex->getMessage()) : $sanitized->getMessage();
        $previous = $this->authenticationMethod === self::AUTHENTICATION_METHOD_QUERY ? null : $sanitized;

        return new RequestException($messagePrefix . $message, (int)$ex->getCode(), $previous);
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
        if ($ex instanceof ConnectException) {
            return new ConnectException(
                $this->redact($ex->getMessage()),
                $this->withoutCredentials($ex->getRequest()),
                null,
                $this->redactContext($ex->getHandlerContext())
            );
        }
        if ($ex instanceof GuzzleRequestException) {
            $request = $this->withoutCredentials($ex->getRequest());
            $context = $this->redactContext($ex->getHandlerContext());
            $original = $ex->getResponse();
            if ($original === null) {
                return $this->copyException($ex, $this->redact($ex->getMessage()), $request, null, $context);
            }
            $response = $this->withoutToken($original, $redactedBody);
            $message = $ex instanceof BadResponseException && $response->getStatusCode() >= 400
                ? GuzzleRequestException::create($request, $response)->getMessage()
                : $this->redact($this->withRedactedBodySummary($ex->getMessage(), $original, $response));

            return $this->copyException($ex, $message, $request, $response, $context);
        }

        // Other exceptions cannot be copied, and their own previous exceptions may carry the token
        return null;
    }

    /**
     * Creates a Guzzle exception of the class of the given one, or of the nearest Guzzle class when a custom
     * subclass cannot be created with the Guzzle constructor arguments.
     * @param GuzzleRequestException $ex exception to copy
     * @param string $message message of the copy
     * @param RequestInterface $request request of the copy
     * @param ResponseInterface|null $response response of the copy
     * @param array $context handler context of the copy
     * @return GuzzleRequestException copy of the exception
     */
    private function copyException(
        GuzzleRequestException $ex,
        string $message,
        RequestInterface $request,
        ?ResponseInterface $response,
        array $context
    ): GuzzleRequestException {
        // The class itself, then its Guzzle ancestors, most specific first
        $classes = array_merge([get_class($ex)], array_intersect(self::GUZZLE_REQUEST_EXCEPTIONS, class_parents($ex)));
        foreach ($classes as $class) {
            try {
                return new $class($message, $request, $response, null, $context);
            } catch (\Throwable $constructorError) {
                // A custom subclass with other constructor arguments: try the nearest Guzzle class
            }
        }

        return new GuzzleRequestException($message, $request, $response, null, $context);
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
        if ($summary === null || $summary === '' || strpos($message, $summary) === false) {
            return $message;
        }

        return str_replace($summary, Message::bodySummary($copy) ?? '', $message);
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
            $response = $response->withHeader($name, array_map(fn (string $value): string => $this->redact($value), $values));
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

            return $this->redact((string)$body);
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
            ->withUri(new Uri($this->redact((string)$request->getUri())), true);
    }

    /**
     * Redacts the access token from the handler context of an HTTP client exception, e.g. the request headers
     * cURL reports. Only strings and nested arrays are kept: objects, and arrays nested deeper than a few
     * levels, may reference themselves and are left out.
     * @param array $context handler context of the exception
     * @param int $depth nesting level of $context
     * @return array context with every string redacted
     */
    private function redactContext(array $context, int $depth = 0): array
    {
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $context[$key] = $this->redact($value);
            } elseif (is_array($value) && $depth < self::CONTEXT_DEPTH_LIMIT) {
                $context[$key] = $this->redactContext($value, $depth + 1);
            } elseif (is_array($value) || is_object($value)) {
                unset($context[$key]);
            }
        }

        return $context;
    }

    /**
     * Redacts the access token from every string, including map keys, in decoded API errors, which may
     * spell the token with JSON escapes the raw body redaction cannot match.
     * @param array $values decoded values, possibly nested in lists, maps and objects
     * @return array values with every string redacted
     */
    private function redactValues(array $values): array
    {
        $redacted = [];
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $value = $this->redact($value);
            } elseif (is_array($value)) {
                $value = $this->redactValues($value);
            } elseif (is_object($value)) {
                $value = (object)$this->redactValues(get_object_vars($value));
            }
            $redacted[is_string($key) ? $this->redact($key) : $key] = $value;
        }

        return $redacted;
    }

    /**
     * Removes the access token from text that may end up in exceptions or logs.
     * @param string $text text possibly containing the access token (e.g. a request URL)
     * @return string text with every form of the access token replaced
     */
    private function redact(string $text): string
    {
        $forms = [];
        $accessToken = $this->currentAccessToken();
        $secrets = [$accessToken, trim($accessToken, " \t"), $this->accessToken];
        $session = $this->session();
        if ($session !== null) {
            if ($session->tokens !== null) {
                $secrets[] = $session->tokens->getRefreshToken();
            }
            array_push($secrets, ...$session->retiredTokens);
        }
        // Guzzle trims blanks from header values, so the server receives, and may echo, the trimmed token
        foreach (array_unique($secrets) as $token) {
            if ($token !== '') {
                array_push(
                    $forms,
                    $token,
                    urlencode($token),
                    rawurlencode($token),
                    substr((string)json_encode($token), 1, -1)
                );
            }
        }

        return $forms === [] ? $text : str_replace(array_unique($forms), '***', $text);
    }

    /**
     * Handle rate limit response (HTTP 429).
     *
     * @param ResponseInterface $response The HTTP response
     * @param string $endpoint API endpoint
     * @param bool $canRetry Whether another request attempt is available
     * @return void
     * @throws RateLimitException If auto-retry is disabled or wait time exceeds maximum
     */
    private function handleRateLimit(
        ResponseInterface $response,
        string $endpoint,
        bool $canRetry
    ): void {
        $retryAfterHeader = $response->getHeaderLine('Retry-After') ?: null;
        $waitSeconds = ($this->rateLimitConfig ?? new RateLimitConfig())->parseRetryAfter($retryAfterHeader);

        $this->logger?->warning('Rate limit hit', [
            'endpoint' => $endpoint,
            'retry_after_seconds' => $waitSeconds,
        ]);

        // If no rate limit config or auto-retry disabled, throw exception
        if ($this->rateLimitConfig === null
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
        sleep($waitSeconds);
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
        ?array $data,
        bool $authenticate = true
    ): Request {
        $userAgent = self::USER_AGENT;
        if ($this->userAgentSuffix !== null) {
            $userAgent .= ' ' . $this->userAgentSuffix;
        }
        $headers = ["User-Agent" => $userAgent, "Content-Type" => "application/json"];

        if ($authenticate
            && $this->authenticationMethod !== self::AUTHENTICATION_METHOD_QUERY
            && preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $this->currentAccessToken()) === 1
        ) {
            // Not a valid header value: Guzzle's error would quote the header value, trimmed, so redact() could miss it
            throw new RequestException('The access token contains a line break or another control character.');
        }
        if (!$authenticate) {
            // login and token refresh requests carry their credentials in the payload
        } elseif ($this->authenticationMethod === self::AUTHENTICATION_METHOD_QUERY) {
            $queryParams['accessToken'] = $this->currentAccessToken();
        } elseif ($this->authenticationMethod === self::AUTHENTICATION_METHOD_BEARER) {
            $headers['Authorization'] = 'Bearer ' . $this->currentAccessToken();
        } else {
            $headers['X-AUTH-TOKEN'] = $this->currentAccessToken();
        }

        // An instance URL that already ends with the API namespace is accepted, as before
        $basePath = rtrim((string)parse_url((string)self::normalizeUrl($this->baseUrl), PHP_URL_PATH), '/');
        $basePath = (string)preg_replace('#/api/v6$#i', '', $basePath);
        $requestUri = $basePath . self::API_NAMESPACE . lcfirst($apiEndpoint) . ".json?" . http_build_query($queryParams);
        try {
            $body = $data !== null ? Utils::jsonEncode($data) : null;
        } catch (InvalidArgumentException $ex) {
            // e.g. a string that is not valid UTF-8; the message does not quote the payload
            throw new RequestException('The request payload cannot be encoded as JSON: ' . $ex->getMessage());
        }

        try {
            return new Request($method, $requestUri, $headers, $body);
        } catch (\InvalidArgumentException $ex) {
            // An invalid header value, e.g. an access token with a trailing newline, is quoted in the message
            throw new RequestException($this->redact($ex->getMessage()));
        }
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
        $responseBody = (string)$httpResponse->getBody();
        if (mb_strlen($responseBody)) {
            try {
                $responseBody = Utils::jsonDecode($responseBody);
            } catch (InvalidArgumentException $ex) {
                $this->logger?->error('Failed to parse API response', [
                    'error' => $ex->getMessage(),
                ]);
                throw new RequestException(
                    $ex->getMessage(),
                    $ex->getCode(),
                    $ex,
                    $httpResponse->getStatusCode(),
                    $this->redact((string)$httpResponse->getBody())
                );
            }
        }

        $errors = is_object($responseBody) ? $this->redactValues($this->normalizeErrors($responseBody->error ?? null)) : [];

        if (!isset($responseBody->result)) {
            $this->logger?->debug('API response received (no result)', [
                'status' => $httpResponse->getStatusCode(),
                'has_errors' => !empty($errors),
            ]);
            return new Response(null, 0, $errors, $httpResponse->getStatusCode());
        }

        $responseData = $responseBody->result->data ?? ($responseBody->result ?? null);
        $total = (int)($responseBody->result->total ?? 1);

        $this->logger?->debug('API response received', [
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
        $instance = new Uri((string)self::normalizeUrl($this->baseUrl));
        $handler = HandlerStack::create();
        // Guzzle removes the Authorization header once a redirect leaves the origin, but not X-AUTH-TOKEN.
        // Every hop is built from the request that still carries the token, so the hops share a state object:
        // the outer middleware creates it, and the one inside allow_redirects sees every hop and, once a hop
        // has left the instance, keeps the token off the rest of the chain.
        $handler->before('allow_redirects', static function (callable $next): callable {
            return static function (RequestInterface $request, array $options) use ($next) {
                $options[self::LEFT_INSTANCE_OPTION] = new \stdClass();

                return $next($request, $options);
            };
        }, 'track_redirects_leaving_instance');
        $handler->after('allow_redirects', static function (callable $next) use ($instance): callable {
            return static function (RequestInterface $request, array $options) use ($next, $instance) {
                $state = $options[self::LEFT_INSTANCE_OPTION] ?? new \stdClass();
                if (self::leavesInstance($instance, $request->getUri())) {
                    $state->left = true;
                }
                if (!empty($state->left)) {
                    $request = $request->withoutHeader('X-AUTH-TOKEN');
                }

                return $next($request, $options);
            };
        }, 'drop_token_outside_instance');

        return new Client([
            'base_uri' => $instance,
            'timeout' => $this->requestTimeout,
            'verify' => $this->verifySsl,
            'handler' => $handler,
        ]);
    }

    /**
     * Checks whether a request URL leaves the Daktela instance: another host, another port, or plain HTTP
     * from an HTTPS instance. Only the upgrade from HTTP to HTTPS on the same host stays on the instance.
     * @param UriInterface $instance URL of the Daktela instance
     * @param UriInterface $target URL the request is sent to
     * @return bool true when the access token must not be sent to the URL
     */
    private static function leavesInstance(UriInterface $instance, UriInterface $target): bool
    {
        if (strcasecmp($target->getHost(), $instance->getHost()) !== 0) {
            return true;
        }
        if (strcasecmp($target->getScheme(), $instance->getScheme()) !== 0) {
            return strcasecmp($target->getScheme(), 'https') !== 0;
        }

        return self::effectivePort($target) !== self::effectivePort($instance);
    }

    /**
     * @param UriInterface $uri URL
     * @return int|null port of the URL, including the default port of its scheme
     */
    private static function effectivePort(UriInterface $uri): ?int
    {
        return $uri->getPort() ?? (['http' => 80, 'https' => 443][strtolower($uri->getScheme())] ?? null);
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
        $methods = [self::AUTHENTICATION_METHOD_HEADER, self::AUTHENTICATION_METHOD_QUERY, self::AUTHENTICATION_METHOD_BEARER];
        if (!in_array($authenticationMethod, $methods, true)) {
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
     * Checks that the API is reachable and accepts the access token.
     * @see healthCheck() for the details of the check
     *
     * @return bool True if the API identifies the user of the access token
     */
    public function ping(): bool
    {
        return $this->healthCheck()['healthy'];
    }

    /**
     * Performs a health check and returns detailed information.
     * Calls the whoim endpoint. The API is healthy only when the response identifies the user of the
     * access token: whoim also answers requests whose token was not accepted, with HTTP 200 and no user.
     * `error` holds the first error message reported by the API, or the transport error when there is none.
     *
     * @return array{healthy: bool, latency_ms: float, status_code?: int, error?: string}
     */
    public function healthCheck(): array
    {
        $startTime = microtime(true);

        try {
            $response = $this->sendRequest('GET', 'whoim');
            if ($this->session()?->tokens !== null && $response->isSuccess() && !$this->identifiesUser($response->getData())) {
                // whoim answers a login session the server has ended with no user instead of HTTP 401
                $this->refreshTokens();
                $response = $this->sendRequest('GET', 'whoim');
            }
        } catch (RequestException $ex) {
            $result = ['healthy' => false, 'latency_ms' => round((microtime(true) - $startTime) * 1000, 2)];
            if ($ex->getHttpStatus() !== null) {
                $result['status_code'] = $ex->getHttpStatus();
            }
            $result['error'] = $this->redact($this->firstErrorMessage($ex->getApiErrors()) ?? $ex->getMessage());

            return $result;
        }

        $result = [
            'healthy' => $response->isSuccess() && $this->identifiesUser($response->getData()),
            'latency_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'status_code' => $response->getHttpStatus(),
        ];
        if (!$result['healthy']) {
            $result['error'] = $this->describeUnhealthyResponse($response);
        }

        return $result;
    }
}
