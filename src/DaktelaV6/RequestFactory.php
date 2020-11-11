<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6;

use Daktela\DaktelaV6\Request\CreateRequest;
use Daktela\DaktelaV6\Request\DeleteRequest;
use Daktela\DaktelaV6\Request\ReadRequest;
use Daktela\DaktelaV6\Request\UpdateRequest;

/**
 * Class RequestFactory creates individual read requests and is used when static
 * initialization of the requests is appropriate or required.
 * @package Daktela\DaktelaV6
 */
class RequestFactory
{

    /**
     * Private RequestFactory constructor in order to disable creating instances.
     */
    private function __construct()
    {
    }

    /**
     * Builds a new empty read request instance.
     * @param string $model model of the REST API to be used
     * @return ReadRequest instance of the read request
     */
    public static function buildReadRequest(string $model): ReadRequest
    {
        return new ReadRequest($model);
    }

    /**
     * Builds a new empty create request instance.
     * @param string $model model of the REST API to be used
     * @return CreateRequest instance of the create request
     */
    public static function buildCreateRequest(string $model): CreateRequest
    {
        return new CreateRequest($model);
    }

    /**
     * Builds a new empty update request instance.
     * @param string $model model of the REST API to be used
     * @return UpdateRequest instance of the update request
     */
    public static function buildUpdateRequest(string $model): UpdateRequest
    {
        return new UpdateRequest($model);
    }

    /**
     * Builds a new empty delete request instance.
     * @param string $model model of the REST API to be used
     * @return DeleteRequest instance of the delete request
     */
    public static function buildDeleteRequest(string $model): DeleteRequest
    {
        return new DeleteRequest($model);
    }
}
