# Daktela V6 PHP Connector

Daktela V6 PHP Connector is a library that enables your PHP application to connect to your [Daktela V6 REST API](https://customer.daktela.com/apihelp/v6/global/general-information). This connector requires you to have the [Daktela Contact Centre](https://daktela.com) application already purchased, installed, and ready for use. The Daktela Contact Centre is an application enabling all-in-one handling of all customer communication coming through various channels, for example calls, e-mails, web chats, SMS, or social media.

## Installation

The recommended way to install is through Composer:

```bash
composer require daktela/daktela-v6-php-connector
```

## Setup

The connector requires following prerequisites:

* Instance URL in the form of https://URL/
* Access token for each access to the Daktela V6 REST API based on required permissions

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

1. By instantiating the connector instance - useful when calling API with one authentication credentials
2. Using static access method - useful when switching access tokens and URL

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

In order to get one specific object for entity use the `RequestFactory::buildbuildReadSingleRequest()` method or use the
method `setObjectName()` passing the object unique name along with `setRequestType(RequestType::TYPE_SINGLE)`:

```php
$request = RequestFactory::buildReadSingleRequest("CampaignsRecords", "records_5fa299a48ab72834012563");

$request = RequestFactory::buildReadRequest("CampaignsRecords")
    ->setRequestType(ReadRequest::TYPE_SINGLE)
    ->setObjectName("records_5fa299a48ab72834012563");
$response = $client->execute($request);
```

If relation data should be read use the `RequestFactory::buildbuildReadRelationRequest()` method or use the
methods `setObjectName()` and `setRelation()` passing the object unique name and relation name along
with `setRequestType(RequestType::TYPE_MULTIPLE)`:

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

When reading all records, if an error occurs during any page request, the operation stops and returns the error. To continue reading despite errors (skipping failed pages), use `setSkipErrorRequests()`:

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
            ["field" => "edited", "operator" => "lte", "2020-11-30 23:59:59"],
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
    ->addIntAttribute("number", 0)
    ->addAttributes(["queue" => 3000]);
$response = $client->execute($request);
```

### Updating entities

```php
$request = RequestFactory::buildUpdateRequest("CampaignsRecords")
    ->setObjectName("records_5fa299a48ab72834012563")
    ->addStringAttribute("number", "00420226211245")
    ->addIntAttribute("number", 0)
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

The response entity contains the parsed data returned by the REST API.

```php
$response   =   $client->execute($request);
$data       =   $response->getData();
$total      =   $response->getTotal();
$errors     =   $response->getErrors();
$httpStatus =   $response->getHttpStatus();
```

## Handling exceptions

In case of a problem with executing the request sent, an exception is usually thrown. All the exceptions are descendants
of the `\DaktelaV6\Exception\RequestException` class. In case a sub-library throws any exception, this exception is
caught and rethrown as a child of this library's class.

You can handle the response exception in standard way using the `try-catch` expression:

```php
use Daktela\DaktelaV6\Exception\RequestException;

try {
    $response = $client->execute($request);
} catch(RequestException $ex) {
    //Exception handling
}
```

## Authentication Methods

The connector supports two authentication methods for passing the access token to the Daktela V6 API:

### 1. Header-based Authentication (Default)

By default, the access token is sent via the `X-AUTH-TOKEN` HTTP header. This is the recommended method as it keeps the token out of URLs and logs.

```php
use Daktela\DaktelaV6\Client;
use Daktela\DaktelaV6\Http\ApiCommunicator;

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

## Retry Mechanism

The connector supports automatic retries with exponential backoff for transient failures:

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
