<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Request;

use Daktela\DaktelaV6\Response\Response;

/**
 * The ARequest class is an abstract class that contains common logic for all request
 * classes and all the request types should inherit this class.
 * @package Daktela\DaktelaV6\Request
 */
abstract class ARequest
{
    /** @var string name of the API model of the request */
    private $model;
    /** @var bool variable used for flagging the request as already executed */
    private $executed = false;
    /** @var Response|null cached response of the request */
    private $response = null;
    /** @var array additional query params that should be sent as part of the HTTP request */
    private $additionalQueryParameters = [];

    /**
     * ARequest constructor.
     * @param string $model name of the API model of the request
     */
    public function __construct(string $model)
    {
        $this->model = $model;
    }

    /**
     * Checks if the request has already been executed.
     * @return bool boolean value if the request has already been executed
     */
    public function isExecuted(): bool
    {
        return $this->executed;
    }

    /**
     * Sets the request as already executed or not.
     * @param bool $executed boolean value if the request has already been executed
     * @noinspection PhpUnused
     */
    public function setExecuted(bool $executed)
    {
        $this->executed = $executed;
    }

    /**
     * Sets the response of the request.
     * @param Response $response response of the request
     * @noinspection PhpUnused
     */
    public function setResponse(Response $response)
    {
        $this->response = $response;
    }

    /**
     * Returns the response of the request.
     * @return Response response of the request
     */
    public function getResponse(): Response
    {
        return $this->response;
    }

    /**
     * Returns the model used for the request.
     * @return string API model used for the request
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Adds additional parameters that should be sent as part of the HTTP query string.
     * @param string $key key of the query string parameter
     * @param string $value value of the query string parameter
     * @return $this current instance of the request to be used as builder pattern
     * @noinspection PhpUnused
     */
    public function addAdditionalQueryParameter(string $key, string $value): self
    {
        $this->additionalQueryParameters[$key] = $value;

        return $this;
    }

    /**
     * Returns the current additional query string parameters configuration.
     * @return array additional query string parameters configuration
     */
    public function getAdditionalQueryParameters(): array
    {
        return $this->additionalQueryParameters;
    }
}
