<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Request;

/**
 * Class CreateRequest represents the Creation (POST) request used for creating entities using
 * Daktela V6 REST API.
 *
 * The class can be used as follows:
 * ```php
 * $request = RequestFactory::buildCreateRequest("CampaignsRecords")
 *     ->addStringAttribute("number", "00420226211245")
 *     ->addIntAttribute("number", 0)
 *     ->addAttributes(["queue" => 3000]);
 * ```
 *
 * @package Daktela\DaktelaV6\Request
 */
class CreateRequest extends ARequestWithAttributes
{
}
