# Daktela V6 PHP Connector

Daktela V6 PHP Connector is a library that enables your PHP application to connect to your [Daktela V6 REST API](https://customer.daktela.com/apihelp/v6/global/general-information). This connector requires you to have the [Daktela Contact Centre](https://daktela.com) application already purchased, installed, and ready for use. The Daktela Contact Centre is an application enabling all-in-one handling of all customer communication coming through various channels, for example calls, e-mails, web chats, SMS, or social media.

## Installation

The connector requires PHP 8.0 or newer, the `mbstring` extension, Guzzle 7.15.2 or newer, and PSR-7 2.12.3 or newer. The recommended way to install it is through Composer:

```bash
composer require daktela/daktela-v6-php-connector:^2.5
```

When upgrading an existing installation, Composer may need permission to update transitive dependencies:

```bash
composer require daktela/daktela-v6-php-connector:^2.5 --with-all-dependencies
```

See [CHANGELOG.md](CHANGELOG.md) for release notes and upgrade requirements.

## Setup

The connector requires the following:

- An instance URL such as `https://mydaktela.daktela.com/`
- An access token with the permissions required by the Daktela V6 REST API operations you call

## Configuration

### HTTP Request Timeout

The default HTTP request timeout is 2 seconds. For operations that may take longer (e.g., reading large datasets), you can increase the timeout:

```php
use Daktela\DaktelaV6\Client;

$client = new Client($instance, $accessToken);
$client->getApiCommunicator()->setRequestTimeout(30.0); // 30 seconds
```

## Usage

There are two ways you can use the Daktela V6 PHP Connector:

1. Instantiate the connector directly when using one set of credentials.
2. Use the static instance accessor when working with multiple instance URL and access-token pairs.

Clients with the same instance URL and access token share the same `ApiCommunicator`, including its authentication method, timeout, SSL verification, User-Agent suffix, logger, custom HTTP client, retry, and rate-limit settings. `Client::getInstance()` also returns the same `Client` object for the same pair. A setting changed through one of them applies to all code that uses the pair. Configure those clients consistently, or give a client its own transport:

```php
use Daktela\DaktelaV6\Client;
use Daktela\DaktelaV6\Http\ApiCommunicator;

$client = new Client($instance, $accessToken, new ApiCommunicator($instance, $accessToken));
```

### 1. Using instance of the connector

```php
use Daktela\DaktelaV6\Client;
use Daktela\DaktelaV6\RequestFactory;

$instance = "https://mydaktela.daktela.com/";
$accessToken = "0b7cb37b6c2b96a4b68128b212c799056564e0f2";

$client = new Client($instance, $accessToken);
$request = RequestFactory::buildReadRequest("Users")
    ->addFilter("username", "eq", "admin");
$response = $client->execute($request);
```

### 2. Using static access methods

```php
use Daktela\DaktelaV6\Client;
use Daktela\DaktelaV6\RequestFactory;

$instance = "https://mydaktela.daktela.com/";
$accessToken = "0b7cb37b6c2b96a4b68128b212c799056564e0f2";

$client = Client::getInstance($instance, $accessToken);
$request = RequestFactory::buildReadRequest("Users")
    ->addFilter("username", "eq", "admin");
$response = $client->execute($request);
```

## Operations

The allowed operations serve for CRUD manipulation with objects. Each operation uses the builder pattern and corresponds
to specific REST action.

### Reading entities

In order to list all objects for specific entities use the `execute()` method:

```php
$request = RequestFactory::buildReadRequest("CampaignsRecords")
    ->addFilter("created", "gte", "2020-11-01 00:00:00")
    ->addSort("created", "asc");
$response = $client->execute($request);
```

To get one specific object, use `RequestFactory::buildReadSingleRequest()` or set its unique name and the `ReadRequest::TYPE_SINGLE` request type explicitly:

```php
$request = RequestFactory::buildReadSingleRequest("CampaignsRecords", "records_5fa299a48ab72834012563");

$request = RequestFactory::buildReadRequest("CampaignsRecords")
    ->setRequestType(ReadRequest::TYPE_SINGLE)
    ->setObjectName("records_5fa299a48ab72834012563");
$response = $client->execute($request);
```

To read relation data, use `RequestFactory::buildReadRelationRequest()` or set the object name, relation name, and `ReadRequest::TYPE_MULTIPLE` request type explicitly:

```php
$request = RequestFactory::buildReadRelationRequest("CampaignsRecords", "records_5fa299a48ab72834012563", "activities");

$request = RequestFactory::buildReadRequest("CampaignsRecords")
    ->setRequestType(ReadRequest::TYPE_MULTIPLE)
    ->setRelation("activities")
    ->setObjectName("records_5fa299a48ab72834012563");
$response = $client->execute($request);
```

Standard loading reads always entities of one page. For pagination use the `setTake()` and `setSkip()` methods.

```php
$request = RequestFactory::buildReadRequest("CampaignsRecords")
    ->setTake(1000)
    ->setSkip(10);
$response = $client->execute($request);
```

To limit which fields are returned in the response, use the `setFields()` method:

```php
$request = RequestFactory::buildReadRequest("Users")
    ->setFields(['name', 'email', 'title']);
$response = $client->execute($request);
```

If you don't want to handle pagination, use the following request type to read all records:

```php
$request = RequestFactory::buildReadRequest("CampaignsRecords")
    ->setRequestType(ReadRequest::TYPE_ALL)
    ->addFilter("created", "gte", "2020-11-01 00:00:00")
    ->addSort("created", "asc");
$response = $client->execute($request);
```

When reading all records, the connector starts at the request's `setSkip()` offset and stops as soon as the API-reported total is reached, even when the last page is full. If the API does not report a total, it stops on a short or empty page. A safety limit caps this operation at 999 page requests; when the limit is reached before all records are read, the response contains the records read so far and an error saying the result is truncated. By default, an error page ends the operation and that response is returned. To continue past error pages, use `setSkipErrorRequests(true)`; error responses with non-array data are skipped:

```php
$request = RequestFactory::buildReadRequest("CampaignsRecords")
    ->setRequestType(ReadRequest::TYPE_ALL)
    ->setSkipErrorRequests(true);
$response = $client->execute($request);
```

You can use different methods for defining filters:

```php
$request = RequestFactory::buildReadRequest("CampaignsRecords")
    ->addFilter("created", "gte", "2020-11-01 00:00:00")
    ->addFilterFromArray([
        ["field" => "edited", "operator" => "lte", "value" => "2020-11-30 23:59:59"],
        ["action", "eq", "0"]
    ])
    ->addSort("created", "asc");
$response = $client->execute($request);
```

Every `addFilter()` and `addFilterFromArray()` call is combined with the filters added before it using AND logic. To use OR logic, specify it in the filter array. The OR group is kept intact as a nested group when it is combined with other filters:

```php
$request = RequestFactory::buildReadRequest("Users")
    ->addFilterFromArray([
        'logic' => 'or',
        'filters' => [
            ["field" => "username", "operator" => "eq", "value" => "admin"],
            ["field" => "username", "operator" => "eq", "value" => "supervisor"]
        ]
    ]);
$response = $client->execute($request);
```

### Creating entities

```php
$request = RequestFactory::buildCreateRequest("CampaignsRecords")
    ->addStringAttribute("number", "00420226211245")
    ->addIntAttribute("action", 0)
    ->addAttributes(["queue" => 3000]);
$response = $client->execute($request);
```

`addAttributes()` skips `null` values so that a partially filled array (for example a database row) does not clear fields by accident. Pass `true` as the second argument to send `null` values, for example to clear a field:

```php
$request = RequestFactory::buildUpdateRequest("Contacts")
    ->setObjectName("contact_123")
    ->addAttributes(["title" => null], true);
```

### Updating entities

```php
$request = RequestFactory::buildUpdateRequest("CampaignsRecords")
    ->setObjectName("records_5fa299a48ab72834012563")
    ->addStringAttribute("number", "00420226211245")
    ->addIntAttribute("action", 0)
    ->addAttributes(["queue" => 3000]);
$response = $client->execute($request);
```

### Deleting entities

```php
$request = RequestFactory::buildDeleteRequest("CampaignsRecords")
    ->setObjectName("records_5fa299a48ab72834012563");
$response = $client->execute($request);
```

Object names and relation names are URL-encoded as single path segments. Values that could address a different endpoint (empty values, `.` and `..`, also percent-encoded, and values containing `/`, `\`, or NUL) are rejected with a `RequestException`, as are model names containing dot segments.

## Processing response

The response entity contains the parsed data returned by the REST API. A successful HTTP status can still contain application-level errors, so inspect `hasErrors()` when the operation requires it.

```php
$response   =   $client->execute($request);
$data       =   $response->getData();
$total      =   $response->getTotal();
$errors     =   $response->getErrors();
$httpStatus =   $response->getHttpStatus();
```

## Handling exceptions

Transport failures and, with Guzzle's default `http_errors` setting, non-retryable HTTP 4xx/5xx responses are wrapped in `Daktela\DaktelaV6\Exception\RequestException`. HTTP 429 responses use the more specific `RateLimitException`, which extends `RequestException` and returns 429 from `getHttpStatus()`.

HTTP 404 responses throw `NotFoundException`, which also extends `RequestException`. For HTTP errors, the exception exposes the response details:

```php
use Daktela\DaktelaV6\Exception\NotFoundException;
use Daktela\DaktelaV6\Exception\RequestException;

try {
    $response = $client->execute($request);
} catch (NotFoundException $ex) {
    // The object does not exist
} catch (RequestException $ex) {
    $status = $ex->getHttpStatus();     // e.g. 400, or null for transport failures
    $errors = $ex->getApiErrors();      // the `error` member of the API response
    $body = $ex->getResponseBody();     // raw response body
}
```

The access token is replaced with `***` in exception messages, the stored response body and API errors, and log entries produced by the connector. With the header methods, `$ex->getPrevious()` is a copy of the Guzzle exception without the token: its request has no credential headers, and the token is replaced in its URL, message, response headers, and body. For a transfer error, a response body larger than 1 MiB is left out of the copy. With query parameter authentication, nothing is chained. Prefer `getHttpStatus()`, `getResponseBody()`, and `getApiErrors()`. The response body may contain personal data, so log it with care.

## Access Tokens

Use a static API token for integrations. An administrator creates it in Manage → Users → [user detail] → Persistent API tokens, or through the `apiToken` API endpoint.

- **Static tokens expire.** Since Daktela 2025.2, each static token has an expiration date (up to 720 days) and can be extended automatically on each use ("Extend with each use"). With an expired, deactivated, or deleted token, or a token of a deactivated user, API endpoints answer HTTP 401 and the health check reports the token as not accepted. Check the expiration date when you set up an integration, and monitor the token with `healthCheck()` (see [Health Check](#health-check)).
- **Dynamic tokens expire quickly.** The access token returned by `login.json` is a short-lived JWT (typically valid for one hour), paired with a refresh token. If you pass such a token to the client yourself, the connector cannot refresh it. Let the connector log in instead (see [Logging In with a Username and Password](#logging-in-with-a-username-and-password)), or use a static token.

Integrations that use a static token need no changes when Daktela is updated to a version that uses JWT sessions for the web interface.

### Logging In with a Username and Password

Instead of using a static token, the connector can log in with a username and password. It refreshes the short-lived access token before it expires, and logs in again when the session has ended:

```php
use Daktela\DaktelaV6\Client;

$client = Client::login($instance, $username, $password);
```

Each login starts a new session on the Daktela server. To reuse one session across processes, pass a `TokenStore` that keeps the tokens in your cache or database:

```php
use Daktela\DaktelaV6\Auth\TokenSet;
use Daktela\DaktelaV6\Auth\TokenStore;
use Daktela\DaktelaV6\Client;

final class FileTokenStore implements TokenStore
{
    public function __construct(private string $path)
    {
    }

    public function load(): ?TokenSet
    {
        $data = is_file($this->path) ? json_decode((string)file_get_contents($this->path), true) : null;

        return is_array($data) ? TokenSet::fromArray($data) : null;
    }

    public function save(TokenSet $tokens): void
    {
        // tempnam() creates the file readable only by its owner; rename() replaces the old file atomically
        $file = tempnam(dirname($this->path), 'daktela');
        file_put_contents($file, json_encode($tokens->toArray()));
        rename($file, $this->path);
    }
}

$client = Client::login($instance, $username, $password, new FileTokenStore('/var/lib/myapp/daktela-tokens.json'));
```

- The password is kept in memory to log in again. The connector never stores it, and keeps it and the tokens out of exception traces and out of `var_dump()` and `print_r()` output.
- Saved tokens record the user and instance they belong to. `login()` logs in again instead of using tokens of another user or instance, so one store can be shared safely. When the store fails, the connector logs a warning and continues with the tokens in memory.
- Refresh tokens are long-lived credentials. Store them as securely as passwords, and use an `https://` instance URL with SSL verification enabled.
- After the server rejects the credentials, the connector sends no further login, which could lock the account, until `login()` is called again.
- When the server rejects the access token with HTTP 401, the connector refreshes it and sends the request once more. The server answers 401 before it processes a request, so the request is not executed twice.
- Login and refresh requests do not follow redirects, because a redirect would send the credentials to its target. A custom HTTP client must respect the `allow_redirects` request option for this.
- Accounts with two-factor authentication cannot log in this way. Use a static token for them.
- To configure the transport before the login (timeout, retries, ...), call `login()` on your own `ApiCommunicator` and pass it to the `Client` constructor.

## Authentication Methods

The connector supports three ways of passing the access token to the Daktela V6 API. It sends the token in exactly one of them.

### 1. X-AUTH-TOKEN Header (Default)

By default, the access token is sent in the `X-AUTH-TOKEN` HTTP header. Every Daktela V6 version accepts it, so it remains the default. The Daktela API documentation lists it as a legacy header in favour of `Authorization: Bearer`, but it is still supported.

```php
use Daktela\DaktelaV6\Client;
$client = new Client($instance, $accessToken);
// Token is automatically sent via X-AUTH-TOKEN header
```

### 2. Authorization: Bearer Header

The standard `Authorization: Bearer <token>` header is the method the Daktela API documentation recommends. It requires Daktela 31.37.0, 32.36.0, 33.14.0, or later. Older versions do not read this header: API endpoints answer HTTP 401, and the health check reports the token as not accepted.

```php
use Daktela\DaktelaV6\Client;
use Daktela\DaktelaV6\Http\ApiCommunicator;

$client = new Client($instance, $accessToken);
$client->getApiCommunicator()->setAuthenticationMethod(
    ApiCommunicator::AUTHENTICATION_METHOD_BEARER
);
```

### 3. Query Parameter Authentication

Alternatively, you can send the access token as a query parameter (`accessToken`). This method may be useful for compatibility with certain proxy configurations or firewall rules.

```php
use Daktela\DaktelaV6\Client;
use Daktela\DaktelaV6\Http\ApiCommunicator;

$client = new Client($instance, $accessToken);
$client->getApiCommunicator()->setAuthenticationMethod(
    ApiCommunicator::AUTHENTICATION_METHOD_QUERY
);
```

To switch back to the default header:

```php
$client->getApiCommunicator()->setAuthenticationMethod(
    ApiCommunicator::AUTHENTICATION_METHOD_HEADER
);
```

**Security Note:** Use one of the header methods in production. They keep the access token out of server logs, browser history, and other places where URLs are typically recorded. The default HTTP client does not send the token to a redirect target outside the instance (another host, another port, or plain HTTP from an HTTPS instance; an upgrade from HTTP to HTTPS on the same host is allowed), and keeps it off for the rest of the redirect chain once a redirect has left the instance. If you set a custom HTTP client, configure its redirect handling yourself.

## Advanced Configuration

### SSL Verification

By default, SSL certificates are verified. For development environments with self-signed certificates, you can disable verification:

```php
$client->getApiCommunicator()->setVerifySsl(false);
```

**Warning:** Never disable SSL verification in production.

### Custom User-Agent

You can append a custom suffix to the User-Agent header for tracking purposes:

```php
$client->getApiCommunicator()->setUserAgentSuffix('MyApp/1.0');
// Results in: "daktela-v6-php-connector MyApp/1.0"
```

### Logging

The connector supports PSR-3 compatible loggers for debugging:

```php
use Psr\Log\LoggerInterface;

// Using any PSR-3 logger (Monolog, etc.)
$client->getApiCommunicator()->setLogger($logger);
```

### Custom HTTP Client

You can inject a custom Guzzle HTTP client for advanced configurations (proxies, middleware, etc.):

```php
use GuzzleHttp\Client as GuzzleClient;

$httpClient = new GuzzleClient([
    'proxy' => 'http://proxy.example.com:8080',
    'timeout' => 60,
]);

$client->getApiCommunicator()->setHttpClient($httpClient);
```

When a custom client is set, configure timeouts, TLS verification, `http_errors`, and other transport options on that client. The connector's `setRequestTimeout()` and `setVerifySsl()` settings are used only when it creates the default Guzzle client.

## Retry Mechanism

The connector supports automatic retries with exponential backoff for configured HTTP status codes and, optionally, connection errors. `maxRetries` is the number of additional attempts after the initial request. With Guzzle's default `http_errors` setting, other HTTP errors continue to throw `RequestException` without retrying.

Create requests (`POST`) are not idempotent: a timeout or a 5xx response does not prove that the server did not create the record, so repeating them could create duplicates. They are therefore retried only when the connection could not be established (DNS, connect, or TLS handshake failure) or on HTTP 429. Set `retryNonIdempotentRequests: true` to retry them in all retryable cases. Read, update, and delete requests are retried in all retryable cases.

```php
use Daktela\DaktelaV6\Http\RetryConfig;

$client->getApiCommunicator()->setRetryConfig(new RetryConfig(
    maxRetries: 3,           // Number of retry attempts
    baseDelayMs: 100,        // Initial delay in milliseconds
    maxDelayMs: 10000,       // Maximum delay between retries
    multiplier: 2.0,         // Exponential backoff multiplier
    retryableStatusCodes: [500, 502, 503, 504],
    retryOnConnectionError: true,
    retryNonIdempotentRequests: false // Do not repeat POST after timeouts/5xx
));

// Quick presets
$client->getApiCommunicator()->setRetryConfig(RetryConfig::aggressive()); // 5 retries
$client->getApiCommunicator()->setRetryConfig(RetryConfig::disabled());   // No retries
```

## Rate Limit Handling

The connector can automatically handle rate limiting (HTTP 429 responses):

```php
use Daktela\DaktelaV6\Http\RateLimitConfig;

$client->getApiCommunicator()->setRateLimitConfig(new RateLimitConfig(
    autoRetry: true,         // Automatically wait and retry
    maxWaitSeconds: 60,      // Maximum time to wait
    defaultWaitSeconds: 5    // Default wait if Retry-After header missing
));
```

With automatic rate-limit handling enabled, the connector performs one retry even when no general `RetryConfig` is set. A positive `maxRetries` value in `RetryConfig` supplies the shared retry budget. The wait comes from either form of the HTTP `Retry-After` header, or from `defaultWaitSeconds`; a `RateLimitException` is thrown if the budget is exhausted or the requested wait exceeds `maxWaitSeconds`.

If rate limiting occurs and `autoRetry` is disabled, a `RateLimitException` is thrown:

```php
use Daktela\DaktelaV6\Exception\RateLimitException;

try {
    $response = $client->execute($request);
} catch (RateLimitException $ex) {
    $waitSeconds = $ex->getRetryAfterSeconds();
    // Handle rate limiting
}
```

## Health Check

`ping()` and `healthCheck()` call the `whoim` endpoint and report the API as healthy only when the response identifies the user of the access token. The endpoint also answers requests whose token was not accepted (invalid, expired, or revoked token, or a deactivated user) with HTTP 200 and no user, and the health check reports those as unhealthy.

```php
// Simple ping
if ($client->ping()) {
    echo "API is reachable and accepts the access token";
}

// Detailed health check
$health = $client->healthCheck();
// Healthy:         ['healthy' => true, 'latency_ms' => 45.2, 'status_code' => 200]
// Token rejected:  ['healthy' => false, 'latency_ms' => 40.1, 'status_code' => 200, 'error' => 'The access token was not accepted: the API did not identify a user.']
// API error:       ['healthy' => false, 'latency_ms' => 52.7, 'status_code' => 400, 'error' => 'New password should be set']
// Network failure: ['healthy' => false, 'latency_ms' => 2001.3, 'error' => 'cURL error 7: Failed to connect ...']
```

`error` contains the first error message reported by the API, in the language of the token's user. Without one, it describes the problem: the token was not accepted, an HTTP error status, an unexpected response (for example, from a wrong instance URL), or the transport error. `status_code` is missing when no HTTP response was received.

`whoim` also checks the state of the user account. For an account with an unfinished password change (its profile requires a one-time password and the new password was never set), it returns HTTP 400 `New password should be set`, although other endpoints still accept the token. Set the new password in the Daktela web interface to make the health check pass.

`whoim` is the request the Daktela web interface makes at startup, and it is heavier than a typical read. Use the health check at startup or for monitoring, not before every request.

## Memory-Efficient Iteration

For large datasets, use the iterator to process records one at a time without loading everything into memory:

```php
$request = RequestFactory::buildReadRequest("CampaignsRecords")
    ->addFilter("created", "gte", "2020-01-01 00:00:00");

// Iterate over all records
foreach ($client->iterate($request) as $record) {
    echo $record->name;
}

// With options
$iterator = $client->iterate(
    $request,
    pageSize: 100,      // Records per API call
    maxItems: 1000,     // Stop after 1000 items
    stopOnError: true   // Stop on first error
);

// Helper methods
$first = $iterator->first();           // Get first item
$all = $iterator->toArray();           // Collect all to array
$count = $iterator->count();           // Count all items
$isEmpty = $iterator->isEmpty();       // Check if empty

// Functional operations
$iterator->each(fn($item) => process($item));
$filtered = $iterator->filter(fn($item) => $item->active);
$mapped = $iterator->map(fn($item) => $item->name);

// Iterate over pages (for access to total counts)
foreach ($iterator->pages() as $response) {
    echo "Total: " . $response->getTotal();
    foreach ($response->getData() as $item) {
        // Process item
    }
}
```

The iterator clones the request and does not mutate the caller's request. It stops at the API-reported total, avoiding an extra empty request when the total is an exact multiple of the page size. If no positive total is reported, it stops on a short or empty page. It starts at the request's `setSkip()` offset.

An error page ends the iteration by default. To tell this apart from the end of the data, check the iterator afterwards:

```php
$iterator = $client->iterate($request);
foreach ($iterator as $record) {
    // ...
}
if ($iterator->hasStoppedOnError()) {
    $errors = $iterator->getErrorResponse()->getErrors();
}
```

Set `stopOnError: false` to skip an error page and continue at the next offset. Error pages are skipped only while the API-reported total shows that more records follow; otherwise the iteration stops and the error response is available as above. For item iteration, a `maxItems` value of zero returns no items and sends no request; `pages()` is independent of that item limit.

Iterator helper methods start a new traversal each time. For example, calling `first()` and then `toArray()` performs separate API reads.

## Response Helper Methods

The response object provides convenient helper methods:

```php
$response = $client->execute($request);

// Check success (HTTP 2xx)
if ($response->isSuccess()) {
    $data = $response->getData();
}

// Check for errors
if ($response->hasErrors()) {
    $firstError = $response->getFirstError();
}

// Check if data is empty
if ($response->isEmpty()) {
    echo "No records found";
}
```

## Development and testing

Project commands are run in Docker. After installing dependencies in the mounted working directory, run the deterministic unit suite with:

```bash
docker run --rm -v "$PWD:/app" -w /app --entrypoint php composer:2 \
    vendor/bin/phpunit --configuration phpunit.dist.xml --testsuite Unit
```

The full suite also contains live API integration tests, which are skipped unless their environment variables are set:

- The request tests need `INSTANCE`, `ACCESS_TOKEN`, and `RECORD_TYPE`.
- The health check tests need `INSTANCE` and `ACCESS_TOKEN`. The Bearer test requires Daktela 31.37.0, 32.36.0, 33.14.0, or later.
- The login test also needs `LOGIN_USERNAME` and `LOGIN_PASSWORD` of an account without two-factor authentication.

CI tests supported PHP versions and enforces at least 90% line coverage for the deterministic unit suite.
