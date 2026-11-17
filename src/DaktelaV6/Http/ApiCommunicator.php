<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Http;

use Daktela\DaktelaV6\Auth\LoginSession;
use Daktela\DaktelaV6\Auth\TokenStore;
use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\Http\Internal\Credentials;
use Daktela\DaktelaV6\Http\Internal\DefaultClientFactory;
use Daktela\DaktelaV6\Http\Internal\ExceptionFactory;
use Daktela\DaktelaV6\Http\Internal\InstanceUrl;
use Daktela\DaktelaV6\Http\Internal\Redactor;
use Daktela\DaktelaV6\Http\Internal\RequestBuilder;
use Daktela\DaktelaV6\Http\Internal\ResponseParser;
use Daktela\DaktelaV6\Http\Internal\SessionManager;
use Daktela\DaktelaV6\Http\Internal\Transport;
use Daktela\DaktelaV6\Response\Response;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Class ApiCommunicator is a transport class of the Daktela V6 communication package and
 * performs the main HTTP operations necessary to perform actions onto Daktela V6 API.
 * @package Daktela\DaktelaV6\Http
 */
class ApiCommunicator
{
    /** @var int Authentication method using HTTP header X-AUTH-TOKEN (legacy; for Daktela versions without Bearer support) */
    public const AUTHENTICATION_METHOD_HEADER = 1;
    /**
     * @var int Authentication method using query parameter accessToken
     * @deprecated since 3.0.0: the token becomes part of the request URL, which servers and proxies log
     */
    public const AUTHENTICATION_METHOD_QUERY = 2;
    /** @var int Authentication method using HTTP header Authorization: Bearer (default; Daktela 31.37.0, 32.36.0, 33.14.0 and later) */
    public const AUTHENTICATION_METHOD_BEARER = 3;
    /** @var string Health check error used when the API answers without identifying the token's user */
    private const TOKEN_NOT_ACCEPTED_ERROR = 'The access token was not accepted: the API did not identify a user.';
    /** @var string Health check error used for an HTTP error response that carries no API error */
    private const HTTP_ERROR_WITHOUT_MESSAGE = 'The API answered HTTP %d without an error message.';
    /** @var string Health check error used for a successful response that is not a whoim response */
    private const UNEXPECTED_RESPONSE_ERROR = 'The API returned an unexpected response without user information; check the instance URL.';
    /** @var array static variable containing all singleton instances of the transport class */
    private static array $singletons = [];
    /** @var string URL of the Daktela instance */
    private string $baseUrl;
    /** @var Credentials static access token, or the login session */
    private Credentials $credentials;
    /** @var float Timeout for HTTP request sent to API */
    private float $requestTimeout = 2.0;
    /** @var int The authentication method to use */
    private int $authenticationMethod = self::AUTHENTICATION_METHOD_BEARER;
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
    /**
     * @var \WeakMap<ApiCommunicator, array{int|false, ClientInterface}>|null default HTTP clients with the id of
     *     the process that created them, built once per object so that
     *     connections are reused. They are kept outside the objects' properties because their handlers are
     *     closures, which serialize() rejects; a clone and an unserialized copy build their own.
     */
    private static ?\WeakMap $defaultClients = null;

    /**
     * ApiCommunicator constructor.
     * @param string $baseUrl URL of the Daktela instance
     * @param string $accessToken access token of user used for connecting to Daktela V6
     */
    public function __construct(string $baseUrl, #[\SensitiveParameter] string $accessToken)
    {
        $this->baseUrl = $baseUrl;
        $this->credentials = new Credentials($accessToken);
    }

    /**
     * A clone keeps the access token or the login session of the original, and a later login() on one of them
     * does not change the other.
     */
    public function __clone()
    {
        $this->credentials = clone $this->credentials;
    }

    /**
     * Static method for using ApiCommunicator client connector as singleton.
     * Every caller with the same URL and access token gets the same object, so its settings
     * (authentication method, timeout, logger, retries, ...) are shared. Create an instance with
     * `new ApiCommunicator()` for independent settings.
     * @param string $baseUrl URL of the Daktela instance
     * @param string $accessToken access token of user used for connecting to Daktela V6
     * @return ApiCommunicator instance of the transport class
     * @deprecated since 3.0.0: create the object with `new ApiCommunicator()` and pass it to the code that needs it
     */
    public static function getInstance(string $baseUrl, #[\SensitiveParameter] string $accessToken): self
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
        if ($this->credentials->session() !== null) {
            $this->sessions()->prepareSession();
        }

        try {
            return $this->dispatch($method, $apiEndpoint, $queryParams, $data, true);
        } catch (RequestException $ex) {
            if ($this->credentials->session()?->tokens === null || $ex->getHttpStatus() !== 401) {
                throw $ex;
            }
            // The server answers 401 before it processes a request, e.g. when it has ended the session,
            // so the request is sent once more after a refresh
            $this->sessions()->refreshTokens();

            return $this->dispatch($method, $apiEndpoint, $queryParams, $data, true);
        }
    }

    /**
     * Starts a session by logging in with a username and password instead of using a static access token.
     * Call it on an ApiCommunicator of its own, not on one shared through getInstance(): the session replaces
     * the user of every holder of the object.
     * The access token of the session is short-lived; the connector refreshes it before it expires, and
     * logs in again when the session has ended. With a token store, the session is reused across processes
     * and no login is sent while the stored refresh token is valid: the password is then not checked, only
     * the user name and the instance the stored tokens belong to. Do not use login() with a token store to
     * check the password of a user who signs in to your application; call it without a store for that.
     * After the server rejects the
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
        $this->credentials->startSession($session);
        $session = null;
        $this->sessions()->start();
    }

    /**
     * @return SessionManager keeps the login session of this object usable
     */
    private function sessions(): SessionManager
    {
        return new SessionManager(
            $this->credentials,
            new Redactor($this->credentials),
            $this->baseUrl,
            $this->logger,
            // The payload of these requests is built from the session where the request is created
            fn (string $apiEndpoint): Response => $this->dispatch('POST', $apiEndpoint, [], null, false)
        );
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
    private function dispatch(
        string $method,
        string $apiEndpoint,
        array $queryParams,
        #[\SensitiveParameter] ?array $data,
        bool $authenticate
    ): Response {
        $client = $this->httpClient ?? $this->defaultClient();
        $redactor = new Redactor($this->credentials);
        $transport = new Transport(
            $client,
            new RequestBuilder($this->baseUrl, $this->credentials, $redactor, $this->authenticationMethod, $this->userAgentSuffix),
            new ResponseParser($redactor, $this->logger),
            new ExceptionFactory($redactor, $this->authenticationMethod !== self::AUTHENTICATION_METHOD_QUERY),
            $this->baseUrl,
            $this->retryConfig,
            $this->rateLimitConfig,
            $this->logger
        );

        return $transport->send($method, $apiEndpoint, $queryParams, $data, $authenticate);
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
        $apiError = ResponseParser::firstErrorMessage($response->getErrors());
        if ($apiError !== null) {
            // Only text received from the API can contain the token
            return (new Redactor($this->credentials))->redact($apiError);
        }
        if (!$response->isSuccess()) {
            return sprintf(self::HTTP_ERROR_WITHOUT_MESSAGE, $response->getHttpStatus());
        }
        $data = $response->getData();

        // whoim always returns the user member; it is null when the token was not accepted
        return is_object($data) && property_exists($data, 'user') ? self::TOKEN_NOT_ACCEPTED_ERROR : self::UNEXPECTED_RESPONSE_ERROR;
    }

    /**
     * Returns the default Guzzle HTTP client of this object, creating it on the first request and after the
     * timeout or the SSL verification setting has changed. A forked process builds its own client: the one
     * of its parent holds open connections, which two processes must not use at once.
     *
     * @return ClientInterface
     * @throws RequestException when the instance URL cannot be parsed
     */
    private function defaultClient(): ClientInterface
    {
        self::$defaultClients ??= new \WeakMap();
        $pid = getmypid();
        $cached = self::$defaultClients[$this] ?? null;
        if ($cached === null || $cached[0] !== $pid) {
            $cached = [$pid, DefaultClientFactory::create($this->baseUrl, $this->requestTimeout, $this->verifySsl, $this->logger)];
            self::$defaultClients[$this] = $cached;
        }

        return $cached[1];
    }

    /**
     * Drops the default HTTP client, so that the next request builds one with the current settings.
     */
    private function resetDefaultClient(): void
    {
        if (self::$defaultClients !== null) {
            unset(self::$defaultClients[$this]);
        }
    }

    /**
     * The HTTP request timeout that should be used when communicating with the associated API.
     * @param float $requestTimeout Timeout of the HTTP request
     * @noinspection PhpUnused
     */
    public function setRequestTimeout(float $requestTimeout): void
    {
        $this->requestTimeout = $requestTimeout;
        $this->resetDefaultClient();
    }

    /**
     * Method for normalizing URL into one standard form the API transport class can use as part of the HTTP request.
     * @param string|null $url URL to be normalized
     * @return string|null normalized URL
     */
    public static function normalizeUrl(?string $url): ?string
    {
        return InstanceUrl::normalize($url);
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
        $this->resetDefaultClient();
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
     * The client needs `base_uri` set to the instance URL, because the connector sends paths relative to it.
     * A failed POST is repeated after a connection error only when the failed transfer went to the instance
     * with the same method, path, and query (see the README, Retry Mechanism).
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
            if ($this->credentials->session()?->tokens !== null && $response->isSuccess() && !$this->identifiesUser($response->getData())) {
                // whoim answers a login session the server has ended with no user instead of HTTP 401
                $this->sessions()->refreshTokens();
                $response = $this->sendRequest('GET', 'whoim');
            }
        } catch (RequestException $ex) {
            $result = ['healthy' => false, 'latency_ms' => round((microtime(true) - $startTime) * 1000, 2)];
            if ($ex->getHttpStatus() !== null) {
                $result['status_code'] = $ex->getHttpStatus();
            }
            $result['error'] = (new Redactor($this->credentials))->redact(ResponseParser::firstErrorMessage($ex->getApiErrors()) ?? $ex->getMessage());

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
