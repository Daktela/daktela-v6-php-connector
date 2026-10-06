<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6;

use Daktela\DaktelaV6\Exception\NotFoundException;
use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\Exception\UnknownRequestTypeException;
use Daktela\DaktelaV6\Http\ApiCommunicator;
use Daktela\DaktelaV6\Iterator\PaginatedIterator;
use Daktela\DaktelaV6\Request\ARequest;
use Daktela\DaktelaV6\Request\CreateRequest;
use Daktela\DaktelaV6\Request\DeleteRequest;
use Daktela\DaktelaV6\Request\ReadRequest;
use Daktela\DaktelaV6\Request\UpdateRequest;
use Daktela\DaktelaV6\Response\Response;

/**
 * The primary client class used to handle request communication to transport layer.
 * The main objective of the Client class is to directly perform request processing
 * based on the provided request type and parameters.
 *
 * The main use case consists of initializing the client class and executing a request:
 * ```php
 * $client = new Client($url, $accessToken);
 * $request = new ReadRequest("Users");
 * $response = $client->execute($request);
 * ```
 *
 * @package Daktela\DaktelaV6
 */
class Client
{
    /** @var array index of all singleton instances of Daktela client */
    private static $singletons = [];
    /** Maximum limit for reading all entities method */
    public const READ_LIMIT = 999;
    /** @var ApiCommunicator API communicator transport class corresponding the instance of client */
    private $apiCommunicator;

    /**
     * Client constructor.
     * By default, all clients created for the same instance and access token share one
     * ApiCommunicator, so transport settings (retries, logger, HTTP client, ...) apply to all of them.
     * Pass a dedicated ApiCommunicator to configure this client independently.
     * @param string $instance URL of the Daktela instance the client is connecting to
     * @param string $accessToken access token of the connecting user
     * @param ApiCommunicator|null $apiCommunicator dedicated transport, or null to use the shared one
     * @noinspection PhpUnused
     */
    public function __construct(string $instance, string $accessToken, ?ApiCommunicator $apiCommunicator = null)
    {
        $this->apiCommunicator = $apiCommunicator ?? ApiCommunicator::getInstance($instance, $accessToken);
    }

    /**
     * Static method for using Daktela client connector as singleton.
     * @param string $instance URL of the Daktela instance the client is connecting to
     * @param string $accessToken access token of the connecting user
     * @return Client instance of the Daktela client class
     * @noinspection PhpUnused
     */
    public static function getInstance(string $instance, string $accessToken): self
    {
        $key = hash('sha256', $instance . "\0" . $accessToken);
        if (!isset(self::$singletons[$key])) {
            self::$singletons[$key] = new Client($instance, $accessToken);
        }

        return self::$singletons[$key];
    }

    /**
     * Executes the provided request on corresponding instance with given access token.
     * The method respects the appropriate behavior of provided request type and performs
     * corresponding action using appropriate REST operation.
     * @param ARequest $request instance of the request to be performed on the Daktela API
     * @return Response immutable object containing the response information
     * @throws UnknownRequestTypeException a request type has been specified incorrectly
     * @throws RequestException a request exception has occurred
     * @noinspection PhpUnused
     */
    public function execute(ARequest $request): Response
    {
        if ($request->isExecuted() && $request->getResponse() !== null) {
            return $request->getResponse();
        }

        if ($request instanceof UpdateRequest) {
            return $this->executeUpdate($request);
        } elseif ($request instanceof CreateRequest) {
            return $this->executeCreate($request);
        } elseif ($request instanceof DeleteRequest) {
            return $this->executeDelete($request);
        } elseif ($request instanceof ReadRequest) {
            switch ($request->getRequestType()) {
                case ReadRequest::TYPE_MULTIPLE:
                    return $this->executeReadMultiple($request);
                case ReadRequest::TYPE_SINGLE:
                    return $this->executeReadSingle($request);
                case ReadRequest::TYPE_ALL:
                    return $this->executeReadAll($request);
            }
        }

        throw new UnknownRequestTypeException();
    }

    /**
     * Performs the Creation action (POST).
     * @param CreateRequest $request instance of the request to be performed on the Daktela API
     * @return Response immutable object containing the response information
     * @throws RequestException a request exception has occurred
     */
    private function executeCreate(CreateRequest $request): Response
    {
        return $this->apiCommunicator->sendRequest(
            "POST",
            $request->getModel(),
            $request->getAdditionalQueryParameters(),
            $request->getAttributes()
        );
    }

    /**
     * Performs the Update action (PUT).
     * @param UpdateRequest $request instance of the request to be performed on the Daktela API
     * @return Response immutable object containing the response information
     * @throws RequestException a request exception has occurred
     */
    private function executeUpdate(UpdateRequest $request): Response
    {
        if ($request->getObjectName() === '') {
            throw new NotFoundException('No object name specified');
        }

        return $this->apiCommunicator->sendRequest(
            "PUT",
            $this->buildEndpoint($request->getModel(), $request->getObjectName()),
            $request->getAdditionalQueryParameters(),
            $request->getAttributes()
        );
    }

    /**
     * Performs the Delete action (DELETE)
     * @param DeleteRequest $request instance of the request to be performed on the Daktela API
     * @return Response immutable object containing the response information
     * @throws RequestException a request exception has occurred
     */
    private function executeDelete(DeleteRequest $request): Response
    {
        if ($request->getObjectName() === '') {
            throw new NotFoundException('No object name specified');
        }

        return $this->apiCommunicator->sendRequest(
            "DELETE",
            $this->buildEndpoint($request->getModel(), $request->getObjectName()),
            $request->getAdditionalQueryParameters()
        );
    }

    /**
     * Performs the Read action (GET) when the client is requesting multiple resulting records.
     * @param ReadRequest $request instance of the request to be performed on the Daktela API
     * @return Response immutable object containing the response information
     * @throws RequestException a request exception has occurred
     */
    private function executeReadMultiple(ReadRequest $request): Response
    {
        return $this->apiCommunicator->sendRequest(
            "GET",
            $this->buildReadEndpoint($request),
            $this->buildReadQuery($request, $request->getSkip())
        );
    }

    /**
     * Performs the Read action (GET) when the client is requesting all resulting records without
     * respect to the pagination. This method therefore provides the pagination up to
     * the READ_LIMIT pages, starting at the skip offset of the request. When the limit is reached
     * before all records are read, an error describing the truncation is added to the response.
     * @param ReadRequest $request instance of the request to be performed on the Daktela API
     * @return Response immutable object containing the response information
     * @throws RequestException a request exception has occurred
     */
    private function executeReadAll(ReadRequest $request): Response
    {
        $response = new Response([], 0, [], 0);
        $endpoint = $this->buildReadEndpoint($request);
        for ($i = 0; $i < self::READ_LIMIT; $i++) {
            $queryParams = $this->buildReadQuery($request, $request->getSkip() + ($i * $request->getTake()));
            $currentResponse = $this->apiCommunicator->sendRequest("GET", $endpoint, $queryParams);

            if (!empty($currentResponse->getErrors()) && !$request->isSkipErrorRequests()) {
                return $currentResponse;
            }

            $currentData = $currentResponse->getData();
            if (!is_array($currentData)) {
                if (!$request->isSkipErrorRequests()) {
                    return $currentResponse;
                }

                // Skip malformed/error pages as requested. The READ_LIMIT
                // loop bound prevents an unbounded sequence of skipped pages.
                continue;
            }

            $data = array_merge($response->getData(), $currentData);
            $response = new Response(
                $data,
                $currentResponse->getTotal(),
                $currentResponse->getErrors(),
                $currentResponse->getHttpStatus()
            );

            //If returned less than take, it is the last page
            if (count($currentData) < $request->getTake()
                || ($currentResponse->getTotal() > 0
                    && count($data) >= $currentResponse->getTotal())
            ) {
                return $response;
            }
        }

        return new Response(
            $response->getData(),
            $response->getTotal(),
            array_merge(
                $response->getErrors(),
                ['Read limit of ' . self::READ_LIMIT . ' pages reached; the result is truncated']
            ),
            $response->getHttpStatus()
        );
    }

    /**
     * Builds the query parameters of a multiple-records read request.
     * @param ReadRequest $request read request
     * @param int $skip skip offset of the page to be read
     * @return array query parameters
     */
    private function buildReadQuery(ReadRequest $request, int $skip): array
    {
        $queryParams = array_merge(
            $request->getAdditionalQueryParameters(),
            [
                'skip' => $skip,
                'take' => $request->getTake(),
                'filter' => $request->getFilters(),
                'sort' => $request->getSorts(),
            ]
        );

        return $this->addFieldsQuery($request, $queryParams);
    }

    /**
     * Adds the requested fields to the query parameters.
     * @param ReadRequest $request read request
     * @param array $queryParams query parameters
     * @return array query parameters including the fields
     */
    private function addFieldsQuery(ReadRequest $request, array $queryParams): array
    {
        if (count($request->getFields()) > 0) {
            //The `$request->getFields()['fields'] ?? $request->getFields()` syntax is a workaround that will be removed in future versions
            $fields = $request->getFields();
            $queryParams = array_merge($queryParams, ['fields' => $fields['fields'] ?? $fields]);
        }

        return $queryParams;
    }

    /**
     * Builds the endpoint of a multiple-records read request (if relational data are read, read them).
     * @param ReadRequest $request read request
     * @return string API endpoint
     * @throws RequestException the object name or relation is not a valid path segment
     */
    private function buildReadEndpoint(ReadRequest $request): string
    {
        if (!is_null($request->getRelation()) && !is_null($request->getObjectName())) {
            return $this->buildEndpoint($request->getModel(), $request->getObjectName(), $request->getRelation());
        }

        return $request->getModel();
    }

    /**
     * Builds an API endpoint, encoding the object name and relation as single path segments.
     * @param string $model API model
     * @param string ...$segments object name and optional relation
     * @return string API endpoint
     * @throws RequestException a segment is a dot segment that would change the endpoint
     */
    private function buildEndpoint(string $model, string ...$segments): string
    {
        $endpoint = $model;
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new RequestException('Invalid object name or relation: ' . $segment);
            }
            $endpoint .= '/' . rawurlencode($segment);
        }

        return $endpoint;
    }

    /**
     * Performs the Read action (GET) when the client is requesting single object.
     * @param ReadRequest $request instance of the request to be performed on the Daktela API
     * @return Response immutable object containing the response information
     * @throws RequestException a request exception has occurred
     */
    private function executeReadSingle(ReadRequest $request): Response
    {
        if ($request->getObjectName() === null || $request->getObjectName() === '') {
            throw new NotFoundException('No object name specified');
        }

        return $this->apiCommunicator->sendRequest(
            "GET",
            $this->buildEndpoint($request->getModel(), $request->getObjectName()),
            $this->addFieldsQuery($request, $request->getAdditionalQueryParameters())
        );
    }

    /**
     * Returns the API communicator assigned to the current Daktela V6 client instance.
     * @return ApiCommunicator|null API communicator assigned to the current Daktela V6 client instance
     * @noinspection PhpPhpUnused
     */
    public function getApiCommunicator(): ?ApiCommunicator
    {
        return $this->apiCommunicator;
    }

    /**
     * Performs a health check to verify API connectivity.
     *
     * @return bool True if the API is reachable and responding
     */
    public function ping(): bool
    {
        return $this->apiCommunicator->ping();
    }

    /**
     * Performs a detailed health check.
     *
     * @return array{healthy: bool, latency_ms: float, status_code?: int, error?: string}
     */
    public function healthCheck(): array
    {
        return $this->apiCommunicator->healthCheck();
    }

    /**
     * Creates a memory-efficient iterator for paginating through large datasets.
     *
     * Example usage:
     * ```php
     * $request = RequestFactory::buildReadRequest("Users")
     *     ->addFilter("active", "eq", "1");
     *
     * foreach ($client->iterate($request) as $user) {
     *     echo $user->name;
     * }
     * ```
     *
     * @param ReadRequest $request The base read request (will be cloned and modified for pagination)
     * @param int $pageSize Number of items per page (default: 100)
     * @param int|null $maxItems Maximum items to return (null for unlimited)
     * @param bool $stopOnError Whether to stop iteration on first error (default: true)
     * @return PaginatedIterator
     */
    public function iterate(
        ReadRequest $request,
        int $pageSize = 100,
        ?int $maxItems = null,
        bool $stopOnError = true
    ): PaginatedIterator {
        return new PaginatedIterator($this, $request, $pageSize, $maxItems, $stopOnError);
    }
}
