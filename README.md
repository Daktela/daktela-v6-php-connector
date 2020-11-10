# Daktela V6 PHP Connector
Daktela V6 PHP Connector is a library that enables your PHP application to connecto to your Daktela V6 REST API.

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

$client = Client::getInstance($instance, $accessToken)
$request = RequestFactory::buildReadRequest("Users")
    ->addFilter("username", "eq", "admin");
$response = $client->execute($request);
```

## Operations
The allowed operations serve for CRUD manipulation with objects. Each operation uses the builder pattern and corresponds to specific REST action.

### Reading entities
In order to list all objects for specific entities use the `execute()` method:
```php
$request = RequestFactory::buildReadRequest("CampaignsRecords")
    ->addFilter("created", "gte", "2020-11-01 00:00:00")
    ->addSort("created", "asc");
$response = $client->execute($request);
```

In order to get one specific object for entity use the `getObjectByName()` method passing the object unique name:
```php
$request = RequestFactory::buildReadRequest("CampaignsRecords")
    ->setRequestType(ReadRequest::TYPE_SINGLE)
    ->setObjectName("records_5fa299a48ab72834012563");
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
    ->addAttribute("number", "00420226211245");
$response = $client->execute($request);
```

### Updating entities
```php
$request = RequestFactory::buildUpdateRequest("CampaignsRecords")
    ->setObject("records_5fa299a48ab72834012563")
    ->addAttribute("number", "00420226211245");
$response = $client->execute($request);
```

### Deleting entities
```php
$request = RequestFactory::buildDeleteRequest("CampaignsRecords")
    ->setObject("records_5fa299a48ab72834012563");
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