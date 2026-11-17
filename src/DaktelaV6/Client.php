<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6;

use Daktela\DaktelaV6\Auth\TokenStore;
use Daktela\DaktelaV6\Exception\InvalidRequestException;
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
    /** @var array<string, Client> clients returned by the deprecated getInstance() */
    private static array $singletons = [];
    /** Maximum limit for reading all entities method */
    public const READ_LIMIT = 999;
    /** @var ApiCommunicator API communicator transport class corresponding the instance of client */
    private ApiCommunicator $apiCommunicator;

    /**
     * Client constructor.
     * Every client has an ApiCommunicator of its own, so its transport settings (retries, logger, HTTP
     * client, ...) apply to this client only. Pass an ApiCommunicator to share one between clients, or to
     * configure it before the client is created.
     * @param string $instance URL of the Daktela instance the client is connecting to
     * @param string $accessToken access token of the connecting user
     * @param ApiCommunicator|null $apiCommunicator transport to use, or null to create one from the instance
     *     URL and the access token
     * @noinspection PhpUnused
     */
    public function __construct(
        string $instance,
        #[\SensitiveParameter] string $accessToken,
        ?ApiCommunicator $apiCommunicator = null
    ) {
        $this->apiCommunicator = $apiCommunicator ?? new ApiCommunicator($instance, $accessToken);
    }

    /**
     * Creates a client that logs in with a username and password instead of using a static access token.
     * It gets its own ApiCommunicator, which keeps the session and refreshes it; see ApiCommunicator::login().
     * To configure the transport (timeout, retries, ...) before the login, create the ApiCommunicator yourself,
     * call its login() method, and pass it to the constructor.
     * @param string $instance URL of the Daktela instance the client is connecting to
     * @param string $username name of the Daktela user
     * @param string $password password of the Daktela user
     * @param TokenStore|null $tokenStore store that keeps the session tokens between processes, or null. While it
     *     holds a valid session of the user, no login is sent and the password is not checked.
     * @return Client client with a logged-in session
     * @throws RequestException when the login fails
     */
    public static function login(
        string $instance,
        string $username,
        #[\SensitiveParameter] string $password,
        ?TokenStore $tokenStore = null
    ): self {
        // PHP records the current values of the parameters in the trace of every exception created below
        // this frame, so the password and the store (which holds tokens) are removed from them
        $secrets = [$password, $tokenStore];
        $password = '';
        $tokenStore = null;
        $apiCommunicator = new ApiCommunicator($instance, '');
        $apiCommunicator->login($username, $secrets[0], $secrets[1]);

        return new self($instance, '', $apiCommunicator);
    }

    /**
     * Static method for using Daktela client connector as singleton.
     * Every caller with the same URL and access token gets the same Client, so transport settings changed
     * by one caller apply to all of them, and the client and its access token stay in memory until the
     * process ends.
     * @param string $instance URL of the Daktela instance the client is connecting to
     * @param string $accessToken access token of the connecting user
     * @return Client instance of the Daktela client class
     * @deprecated since 3.0.0: create the client with `new Client()` and pass it to the code that needs it
     * @noinspection PhpUnused
     */
    public static function getInstance(string $instance, #[\SensitiveParameter] string $accessToken): self
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
            $this->validateModel($request->getModel()),
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
        if ($request->getTake() < 1) {
            // The offset would not advance: the same page would be read again and again
            throw new InvalidRequestException('The take of a request reading all records must be at least 1');
        }
        $data = [];
        $total = 0;
        $errors = [];
        $httpStatus = 0;
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

            // Appended in place: merging into a new array for every page copies all records read so far
            foreach ($currentData as $record) {
                $data[] = $record;
            }
            $total = $currentResponse->getTotal();
            $errors = $currentResponse->getErrors();
            $httpStatus = $currentResponse->getHttpStatus();

            //If returned less than take, it is the last page
            if (
                count($currentData) < $request->getTake()
                || ($total > 0 && $request->getSkip() + count($data) >= $total)
            ) {
                return new Response($data, $total, $errors, $httpStatus);
            }
        }

        return new Response(
            $data,
            $total,
            array_merge($errors, ['Read limit of ' . self::READ_LIMIT . ' pages reached; the result is truncated']),
            $httpStatus
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
     * @throws RequestException the object name or relation is not a valid path segment, or a relation is set
     *     without the object it belongs to
     */
    private function buildReadEndpoint(ReadRequest $request): string
    {
        if (!is_null($request->getRelation()) && !is_null($request->getObjectName())) {
            return $this->buildEndpoint($request->getModel(), $request->getObjectName(), $request->getRelation());
        }
        if (!is_null($request->getRelation())) {
            // Without the object, the request would read the records of the model instead of the relation
            throw new InvalidRequestException('A relation needs an object name: call setObjectName() as well');
        }

        return $this->validateModel($request->getModel());
    }

    /**
     * Builds an API endpoint, encoding the object name and relation as single path segments.
     * Segments that could address a different endpoint are rejected rather than encoded, because
     * some server setups decode `%2F` before resolving the path.
     * @param string $model API model
     * @param string ...$segments object name and optional relation
     * @return string API endpoint
     * @throws RequestException the model or a segment would change the addressed endpoint
     */
    private function buildEndpoint(string $model, string ...$segments): string
    {
        $endpoint = $this->validateModel($model);
        foreach ($segments as $segment) {
            $decoded = rawurldecode($segment);
            if (
                $segment === ''
                || $decoded === '.'
                || $decoded === '..'
                || strpbrk($decoded, "/\\\0") !== false
            ) {
                throw new InvalidRequestException('Invalid object name or relation: ' . $segment);
            }
            $endpoint .= '/' . rawurlencode($segment);
        }

        return $endpoint;
    }

    /**
     * Validates that the model does not contain dot segments that would leave the API namespace, or a
     * query string or fragment, which would add or cut off query parameters.
     * @param string $model API model
     * @return string validated API model
     * @throws RequestException the model contains a dot segment, `?`, `#`, or a NUL byte
     */
    private function validateModel(string $model): string
    {
        // A percent-encoded `?` or `#` stays a part of the path
        if (strpbrk($model, '?#') !== false || str_contains(rawurldecode($model), "\0")) {
            throw new InvalidRequestException('Invalid model: ' . $model);
        }
        foreach (preg_split('#[/\\\\]#', rawurldecode($model)) as $part) {
            if ($part === '.' || $part === '..') {
                throw new InvalidRequestException('Invalid model: ' . $model);
            }
        }

        return $model;
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
     * @return ApiCommunicator API communicator assigned to the current Daktela V6 client instance
     * @noinspection PhpUnused
     */
    public function getApiCommunicator(): ApiCommunicator
    {
        return $this->apiCommunicator;
    }

    /**
     * Checks that the API is reachable and accepts the access token.
     *
     * @return bool True if the API identifies the user of the access token
     */
    public function ping(): bool
    {
        return $this->apiCommunicator->ping();
    }

    /**
     * Performs a detailed health check. The API is healthy only when it identifies the user of the access token;
     * `error` holds the first error message reported by the API, or the transport error.
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
     * @throws InvalidRequestException when the page size is less than 1
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
