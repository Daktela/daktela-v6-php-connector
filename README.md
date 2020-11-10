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
use Daktela\DaktelaV6\V6;

$instance = "https://mydaktela.daktela.com/";
$accessToken = "0b7cb37b6c2b96a4b68128b212c799056564e0f2";

$client = new V6($instance, $accessToken);
$request = $client->buildReadRequest("Users")
    ->addFilter("username", "eq", "admin")
    ->execute();
$response = $request->getResponse();
```

### 2. Using static access methods
```php
use Daktela\DaktelaV6\V6;

$instance = "https://mydaktela.daktela.com/";
$accessToken = "0b7cb37b6c2b96a4b68128b212c799056564e0f2";

$request = V6::getInstance($instance, $accessToken)
    ->buildReadRequest("Users")
    ->addFilter("username", "eq", "admin")
    ->execute();
$response = $request->getResponse();
```

## Operations
The allowed operations serve for CRUD manipulation with objects. Each operation uses the builder pattern and corresponds to specific REST action.

### Getting entities
In order to list all objects for specific entities use the `find()` method:
```php
$request = $client->buildReadRequest("CampaignsRecords")
    ->addFilter("created", "gte", "2020-11-01 00:00:00")
    ->addSort("created", "asc")
    ->execute();
$response = $request->getResponse();
```

In order to get one specific object for entity use the `getObjectByName()` method passing the object unique name:
```php
$campaignRecord = $client->buildReadRequest("CampaignsRecords")
    ->getObjectByName("records_5fa299a48ab72834012563");
```

You can use different methods for defining filters:
```php
$request = $client->buildReadRequest("CampaignsRecords")
    ->addFilter("created", "gte", "2020-11-01 00:00:00")
    ->addFilterFromArray([
            ["field" => "edited", "operator" => "lte", "2020-11-30 23:59:59"],
            ["action", "eq", "0"]
        ])
    ->addSort("created", "asc")
    ->execute();
$response = $request->getResponse();
```

### Creating entities
```php
$request = $client->buildCreateRequest("CampaignsRecords")
    ->addAttribute("number", "00420226211245")
    ->execute();
$response = $request->getResponse();
```

### Updating entities
```php
$request = $client->buildUpdateRequest("CampaignsRecords")
    ->setObject("records_5fa299a48ab72834012563")
    ->addAttribute("number", "00420226211245")
    ->execute();
$response = $request->getResponse();
```

### Deleting entities
```php
$request = $client->buildDeleteRequest("CampaignsRecords")
    ->setObject("records_5fa299a48ab72834012563")
    ->execute();
$response = $request->getResponse();
```

## Processing response
The response entity contains the parsed data returned by the REST API.
```php
$response   =   $request->getResponse();
$data       =   $response->getData();
$total      =   $response->getTotal();
$errors     =   $response->getErrors();
$httpStatus =   $response->getHttpStatus();
```