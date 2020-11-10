<?php
declare(strict_types=1);

namespace Daktela\DaktelaV6\Request;

use Daktela\DaktelaV6\Http\ApiCommunicator;
use Daktela\DaktelaV6\Response\Response;

class DeleteRequest extends ARequest
{
    private $objectName;

    public function setObjectName(string $objectName): self
    {
        $this->objectName = $objectName;
        return $this;
    }

    public function getObjectName(): string
    {
        return $this->objectName;
    }

}
