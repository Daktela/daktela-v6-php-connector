<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6;

use Daktela\DaktelaV6\Exception\NotFoundException;
use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\Exception\UnknownRequestTypeException;
use Daktela\DaktelaV6\Http\ApiCommunicator;
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
     * @param string $instance URL of the Daktela instance the client is connecting to
     * @param string $accessToken access token of the connecting user
     * @noinspection PhpUnused
     */
    public function __construct(string $instance, string $accessToken)
    {
        $this->apiCommunicator = ApiCommunicator::getInstance($instance, $accessToken);
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
        $key = md5($instance . $accessToken);
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
        if ($request->isExecuted()) {
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
        if (empty($request->getObjectName())) {
            throw new NotFoundException('No object name specified');
        }

        return $this->apiCommunicator->sendRequest(
            "PUT",
            $request->getModel() . "/" . $request->getObjectName(),
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
        if (empty($request->getObjectName())) {
            throw new NotFoundException('No object name specified');
        }

        return $this->apiCommunicator->sendRequest(
            "DELETE",
            $request->getModel() . "/" . $request->getObjectName(),
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
        $queryParams = array_merge(
            $request->getAdditionalQueryParameters(),
            [
                'skip' => $request->getSkip(),
                'take' => $request->getTake(),
                'filter' => $request->getFilters(),
                'sort' => $request->getSorts(),
            ]
        );

        /** @noinspection DuplicatedCode */
        if (count($request->getFields()) > 0) {
            //The `$request->getFields()['fields'] ?: $request->getFields()` syntax is a workaround that will be removed in future versions
            $fields = $request->getFields();
            $queryParams = array_merge($queryParams, ['fields' => $fields['fields'] ?? $fields]);
        }

        //Define the API endpoint (if relational data are read, read them)
        $endpoint = $request->getModel();
        if (!is_null($request->getRelation()) && !is_null($request->getObjectName())) {
            $endpoint .= "/" . $request->getObjectName() . "/" . $request->getRelation();
        }

        return $this->apiCommunicator->sendRequest("GET", $endpoint, $queryParams);
    }

    /**
     * Performs the Read action (GET) when the client is requesting all resulting records without
     * respect to the pagination. This method therefore provides the pagination up to
     * the READ_LIMIT specified as constant of this class.
     * @param ReadRequest $request instance of the request to be performed on the Daktela API
     * @return Response immutable object containing the response information
     * @throws RequestException a request exception has occurred
     */
    private function executeReadAll(ReadRequest $request): Response
    {
        $response = new Response([], 0, [], 0);
        for ($i = 0; $i < self::READ_LIMIT; $i++) {
            $queryParams = array_merge(
                $request->getAdditionalQueryParameters(),
                [
                    'skip' => ($i * $request->getTake()),
                    'take' => $request->getTake(),
                    'filter' => $request->getFilters(),
                    'sort' => $request->getSorts(),
                ]
            );

            /** @noinspection DuplicatedCode */
            if (count($request->getFields()) > 0) {
                //The `$request->getFields()['fields'] ?: $request->getFields()` syntax is a workaround that will be removed in future versions
                $fields = $request->getFields();
                $queryParams = array_merge($queryParams, ['fields' => $fields['fields'] ?? $fields]);
            }

            //Define the API endpoint (if relational data are read, read them)
            $endpoint = $request->getModel();
            if (!is_null($request->getRelation()) && !is_null($request->getObjectName())) {
                $endpoint .= "/" . $request->getObjectName() . "/" . $request->getRelation();
            }

            $currentResponse = $this->apiCommunicator->sendRequest("GET", $endpoint, $queryParams);

            if (!empty($currentResponse->getErrors()) && !$request->isSkipErrorRequests()) {
                return $currentResponse;
            }

            $data = [];
            if (is_array($currentResponse->getData())) {
                $data = array_merge($response->getData(), $currentResponse->getData());
            } elseif (!$request->isSkipErrorRequests()) {
                return $currentResponse;
            }
            $response = new Response(
                $data,
                $currentResponse->getTotal(),
                $currentResponse->getErrors(),
                $currentResponse->getHttpStatus()
            );

            //If returned less than take, it is the last page
            if (count($currentResponse->getData()) < $request->getTake()) {
                break;
            }
        }

        return $response;
    }

    /**
     * Performs the Read action (GET) when the client is requesting single object.
     * @param ReadRequest $request instance of the request to be performed on the Daktela API
     * @return Response immutable object containing the response information
     * @throws RequestException a request exception has occurred
     */
    private function executeReadSingle(ReadRequest $request): Response
    {
        if (empty($request->getObjectName())) {
            throw new NotFoundException('No object name specified');
        }

        $queryParams = $request->getAdditionalQueryParameters();
        if (count($request->getFields()) > 0) {
            //The `$request->getFields()['fields'] ?: $request->getFields()` syntax is a workaround that will be removed in future versions
            $fields = $request->getFields();
            $queryParams = array_merge($queryParams, ['fields' => $fields['fields'] ?? $fields]);
        }

        return $this->apiCommunicator->sendRequest(
            "GET",
            $request->getModel() . "/" . $request->getObjectName(),
            $queryParams
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
}
