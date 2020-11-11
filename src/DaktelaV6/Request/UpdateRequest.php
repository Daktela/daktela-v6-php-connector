<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Request;

/**
 * Class UpdateRequest represents the Update (PUT) request used for updating entities using
 * Daktela V6 REST API.
 *
 * The class can be used as follows:
 * ```php
 * $request = RequestFactory::buildUpdateRequest("CampaignsRecords")
 *     ->setObjectName("records_5fa299a48ab72834012563")
 *     ->addStringAttribute("number", "00420226211245")
 *     ->addIntAttribute("number", 0)
 *     ->addAttributes(["queue" => 3000]);
 * ```
 *
 * @package Daktela\DaktelaV6\Request
 */
class UpdateRequest extends ARequestWithAttributes
{
    /** @var string Unique object name that is supposed to be updated */
    private $objectName;

    /**
     * Method for setting the object name to be updated.
     * @param string $objectName unique name of the object that should be updated
     * @return $this current instance of the request to be used as builder pattern
     */
    public function setObjectName(string $objectName): self
    {
        $this->objectName = $objectName;
        return $this;
    }

    /**
     * Method for obtaining the object name to be updated.
     * @return string unique name of the object that is supposed to be updated
     */
    public function getObjectName(): string
    {
        return $this->objectName;
    }
}
