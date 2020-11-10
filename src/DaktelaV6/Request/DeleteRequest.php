<?php
declare(strict_types=1);

namespace Daktela\DaktelaV6\Request;

use Daktela\DaktelaV6\Response\Response;

class DeleteRequest extends ARequest
{
    private $model;
    private $objectName;

    public function __construct(string $instance, string $accessToken, string $model)
    {
        parent::__construct($instance, $accessToken);
        $this->model = $model;
    }

    public function setObjectName(string $objectName): self
    {
        $this->objectName = $objectName;
        return $this;
    }

    public function getObjectName(): string
    {
        return $this->objectName;
    }

    protected function executeRequest(): Response
    {
        if (is_null($this->objectName) || empty($this->objectName)) {
            return new Response(null, -1, ['No object name specified'], 0);
        }
        return $this->sendRequest("DELETE", $this->model . "/" . $this->objectName);
    }
}
