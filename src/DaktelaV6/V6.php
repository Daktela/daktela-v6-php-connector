<?php
declare(strict_types=1);

namespace Daktela\DaktelaV6;

use Daktela\DaktelaV6\Request\CreateRequest;
use Daktela\DaktelaV6\Request\DeleteRequest;
use Daktela\DaktelaV6\Request\ReadRequest;
use Daktela\DaktelaV6\Request\UpdateRequest;

class V6
{
    private static $singletons;
    private $url;
    private $accessToken;

    public function __construct(string $url, string $accessToken)
    {
        $this->url = $url;
        $this->accessToken = $accessToken;
    }

    public function buildReadRequest(string $model): ReadRequest
    {
        return new ReadRequest($this->url, $this->accessToken, $model);
    }

    public function buildCreateRequest(string $model): CreateRequest
    {
        return new CreateRequest($this->url, $this->accessToken, $model);
    }

    public function buildUpdateRequest(string $model): UpdateRequest
    {
        return new UpdateRequest($this->url, $this->accessToken, $model);
    }

    public function buildDeleteRequest(string $model): DeleteRequest
    {
        return new DeleteRequest($this->url, $this->accessToken, $model);
    }

    public static function getInstance($url, $accessToken)
    {
        $key = md5($url . $accessToken);
        if (!isset(self::$singletons[$key])) {
            self::$singletons[$key] = new V6($url, $accessToken);
        }
        return self::$singletons[$key];
    }
}