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

    /**
     * ARequest constructor.
     * @param $model name of the API model of the request
     */
    public function __construct($model)
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
     */
    public function setExecuted(bool $executed)
    {
        $this->executed = $executed;
    }

    /**
     * Sets the response of the request.
     * @param Response $response response of the request
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
}
