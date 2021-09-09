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
     * @noinspection PhpUnused
     */
    private function __construct()
    {
    }

    /**
     * Builds a new empty read request instance.
     * @param string $model model of the REST API to be used
     * @return ReadRequest instance of the read request
     * @noinspection PhpUnused
     */
    public static function buildReadRequest(string $model): ReadRequest
    {
        return new ReadRequest($model);
    }

    /**
     * Builds a new empty read request instance for reading one specific object.
     * @param string $model model of the REST API to be used
     * @param string $entityName unique name of the object to be loaded
     * @return ReadRequest instance of the read request
     * @noinspection PhpUnused
     */
    public static function buildReadSingleRequest(string $model, string $entityName): ReadRequest
    {
        return self::buildReadRequest($model)->setRequestType(ReadRequest::TYPE_SINGLE)->setObjectName($entityName);
    }

    /**
     * Builds a new empty read request instance for reading multiple objects.
     * @param string $model model of the REST API to be used
     * @return ReadRequest instance of the read request
     * @noinspection PhpUnused
     */
    public static function buildReadMultipleRequest(string $model): ReadRequest
    {
        return self::buildReadRequest($model)->setRequestType(ReadRequest::TYPE_MULTIPLE);
    }

    /**
     * Builds a new empty read request instance for reading relation data of one specific object.
     * @param string $model model of the REST API to be used
     * @param string $entityName unique name of the object to be loaded
     * @param string $relation name of the relation
     * @return ReadRequest instance of the read request
     * @noinspection PhpUnused
     */
    public static function buildReadRelationRequest(string $model, string $entityName, string $relation): ReadRequest
    {
        return self::buildReadRequest($model)
            ->setRequestType(ReadRequest::TYPE_MULTIPLE)
            ->setObjectName($entityName)
            ->setRelation($relation);
    }

    /**
     * Builds a new empty create request instance.
     * @param string $model model of the REST API to be used
     * @return CreateRequest instance of the create request
     * @noinspection PhpUnused
     */
    public static function buildCreateRequest(string $model): CreateRequest
    {
        return new CreateRequest($model);
    }

    /**
     * Builds a new empty update request instance.
     * @param string $model model of the REST API to be used
     * @return UpdateRequest instance of the update request
     * @noinspection PhpUnused
     */
    public static function buildUpdateRequest(string $model): UpdateRequest
    {
        return new UpdateRequest($model);
    }

    /**
     * Builds a new empty delete request instance.
     * @param string $model model of the REST API to be used
     * @return DeleteRequest instance of the delete request
     * @noinspection PhpUnused
     */
    public static function buildDeleteRequest(string $model): DeleteRequest
    {
        return new DeleteRequest($model);
    }
}
