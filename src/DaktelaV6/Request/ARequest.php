<?php
declare(strict_types=1);

namespace Daktela\DaktelaV6\Request;

use Daktela\DaktelaV6\Response\Response;

abstract class ARequest
{
    private $model;
    private $executed = false;
    private $response = null;

    public function __construct($model)
    {
        $this->model = $model;
    }

    public function isExecuted(): bool
    {
        return $this->executed;
    }

    public function setExecuted(bool $executed)
    {
        $this->executed = $executed;
    }

    public function setResponse(Response $response)
    {
        $this->response = $response;
    }

    public function getResponse(): Response
    {
        return $this->response;
    }

    public function getModel(): string
    {
        return $this->model;
    }

}