# Daktela V6 PHP Connector

Daktela V6 PHP Connector is a library that enables your PHP application to connect to your Daktela V6 REST API.

## Setup

The connector requires following prerequisites:

* Instance URL in the form of https://URL/
* Access token for each access to the Daktela V6 REST API based on required permissions

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

If you don't want to handle pagination, use the following request type to read all records:

```php
$request = RequestFactory::buildReadRequest("CampaignsRecords")
    ->setRequestType(ReadRequest::TYPE_ALL)
    ->addFilter("created", "gte", "2020-11-01 00:00:00")
    ->addSort("created", "asc");
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
