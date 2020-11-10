<?php
declare(strict_types=1);

namespace Daktela\DaktelaV6;

use Daktela\DaktelaV6\Request\CreateRequest;
use Daktela\DaktelaV6\Request\DeleteRequest;
use Daktela\DaktelaV6\Request\ReadRequest;
use Daktela\DaktelaV6\Request\UpdateRequest;

class RequestFactory
{

    private function __construct()
    {

    }

    public static function buildReadRequest(string $model): ReadRequest
    {
        return new ReadRequest($model);
    }

    public static function buildCreateRequest(string $model): CreateRequest
    {
        return new CreateRequest($model);
    }

    public static function buildUpdateRequest(string $model): UpdateRequest
    {
        return new UpdateRequest($model);
    }

    public static function buildDeleteRequest(string $model): DeleteRequest
    {
        return new DeleteRequest($model);
    }
}