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

Clients with the same instance URL and access token share the same `ApiCommunicator`, including its timeout, logger, custom HTTP client, retry, and rate-limit settings. Configure those clients consistently.

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

When reading all records, the connector stops as soon as the API-reported total is reached, even when the last page is full. If the API does not report a total, it stops on a short or empty page. A safety limit caps this operation at 999 page requests. By default, an error page ends the operation and that response is returned. To continue past error pages, use `setSkipErrorRequests(true)`; error responses with non-array data are skipped:

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

By default, multiple filters are combined with AND logic. To use OR logic, specify it in the filter array:

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

Transport failures and, with Guzzle's default `http_errors` setting, non-retryable HTTP 4xx/5xx responses are wrapped in `Daktela\DaktelaV6\Exception\RequestException`. HTTP 429 responses use the more specific `RateLimitException`, which extends `RequestException`.

You can handle the response exception in standard way using the `try-catch` expression:

```php
use Daktela\DaktelaV6\Exception\RequestException;

try {
    $response = $client->execute($request);
} catch (RequestException $ex) {
    // Exception handling
}
```

## Authentication Methods

The connector supports two authentication methods for passing the access token to the Daktela V6 API:

### 1. Header-based Authentication (Default)

By default, the access token is sent via the `X-AUTH-TOKEN` HTTP header. This is the recommended method as it keeps the token out of URLs and logs.

```php
use Daktela\DaktelaV6\Client;
$client = new Client($instance, $accessToken);
// Token is automatically sent via X-AUTH-TOKEN header
```

### 2. Query Parameter Authentication

Alternatively, you can send the access token as a query parameter (`accessToken`). This method may be useful for compatibility with certain proxy configurations or firewall rules.

```php
use Daktela\DaktelaV6\Client;
use Daktela\DaktelaV6\Http\ApiCommunicator;

$client = new Client($instance, $accessToken);
$client->getApiCommunicator()->setAuthenticationMethod(
    ApiCommunicator::AUTHENTICATION_METHOD_QUERY
);
```

To switch back to header-based authentication:

```php
$client->getApiCommunicator()->setAuthenticationMethod(
    ApiCommunicator::AUTHENTICATION_METHOD_HEADER
);
```

**Security Note:** Header-based authentication is recommended for production use as it prevents the access token from appearing in server logs, browser history, and other places where URLs are typically recorded.

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

```php
use Daktela\DaktelaV6\Http\RetryConfig;

$client->getApiCommunicator()->setRetryConfig(new RetryConfig(
    maxRetries: 3,           // Number of retry attempts
    baseDelayMs: 100,        // Initial delay in milliseconds
    maxDelayMs: 10000,       // Maximum delay between retries
    multiplier: 2.0,         // Exponential backoff multiplier
    retryableStatusCodes: [500, 502, 503, 504],
    retryOnConnectionError: true
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

You can verify API connectivity before making requests:

```php
// Simple ping
if ($client->ping()) {
    echo "API is reachable";
}

// Detailed health check
$health = $client->healthCheck();
// Returns: ['healthy' => true, 'latency_ms' => 45.2, 'status_code' => 200]
// Or on error: ['healthy' => false, 'latency_ms' => 1000.5, 'error' => 'Connection refused']
```

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

The iterator clones the request and does not mutate the caller's request. It stops at the API-reported total, avoiding an extra empty request when the total is an exact multiple of the page size. If no positive total is reported, it stops on a short or empty page. Set `stopOnError: false` to skip an error page and continue at the next offset. For item iteration, a `maxItems` value of zero returns no items and sends no request; `pages()` is independent of that item limit.

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

The full suite also contains live API integration tests. They are skipped unless all three environment variables are present:

- `INSTANCE`
- `ACCESS_TOKEN`
- `RECORD_TYPE`

CI tests supported PHP versions and enforces at least 90% line coverage for the deterministic unit suite.
