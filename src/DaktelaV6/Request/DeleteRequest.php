<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Request;

class DeleteRequest extends ARequest
{
    /** @var string Unique object name that is supposed to be deleted */
    private $objectName;

    /**
     * Method for setting the object name to be deleted.
     * @param string $objectName unique name of the object that should be deleted
     * @return $this current instance of the request to be used as builder pattern
     */
    public function setObjectName(string $objectName): self
    {
        $this->objectName = $objectName;
        return $this;
    }

    /**
     * Method for obtaining the object name to be deleted.
     * @return string unique name of the object that is supposed to be deleted
     */
    public function getObjectName(): string
    {
        return $this->objectName;
    }

}
