<?php

declare(strict_types=1);

namespace Daktela;

use Daktela\Request\Filter;
use Daktela\Request\IRequest;
use Daktela\Response\Response;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use InvalidArgumentException;
use function json_decode;

/**
 * @author Petr Kalíšek <petr.kalisek@daktela.com>
 */
abstract class BaseDaktela implements IRequest
{

    const DIRECTION_ASC = 'asc';
    const DIRECTION_DESC = 'desc';
    
    /**
     * @var string
     */
    private $accessToken = null;

    /**
     * @var array
     */
    private $queryData = [
        'accessToken' => null,
    ];

    /**
     * @var string[]
     */
    private $supportedDirections = [self::DIRECTION_ASC, self::DIRECTION_DESC];

    /**
     * @var string[]
     */
    private $supportedFilterLogics = [Filter::FILTER_LOGIC_OR, Filter::FILTER_LOGIC_AND];

    /**
     * @var string[]
     */
    private $attributes = [];

    /**
     * @var Client|null
     */
    private $client = null;

    /**
     * @var IConfig|null
     */
    private $config = null;

    public function __construct(IConfig $config)
    {
        $this->config = $config;
        $this->accessToken = $config->getAccessToken();

        $_config = [];
        $_config['base_uri'] = $config->getInstanceUri();

        $this->client = new Client($_config);
    }

    abstract public function getMethod(): string;

    abstract protected function getModelClass(): ?string;

    public function limit(int $limit): IRequest
    {
        return $this->take($limit);
    }

    public function skip(int $skip): IRequest
    {
        $this->queryData['skip'] = $skip;
        return $this;
    }

    public function take(int $take): IRequest
    {
        $this->queryData['take'] = $take;
        return $this;
    }

    public function addSort(string $field, string $dir = self::DIRECTION_ASC): IRequest
    {
        $dirLower = strtolower($dir);

        if (!in_array($dirLower, $this->supportedDirections)) {
            throw new InvalidArgumentException(sprintf('Direction %s is not supported, Availables: %s', $dir, implode(', ', $this->supportedDirections)));
        }

        $this->queryData['sort'][] = ['field' => $field, 'dir' => $dirLower];
        return $this;
    }

    public function addAttribute(string $key, string $value): IRequest
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function addAttributes(array $attributes): IRequest
    {
        $this->attributes = array_merge($this->attributes, $attributes);
        return $this;
    }

    public function setFilter(array $filter, string $logic = Filter::FILTER_LOGIC_AND): IRequest
    {

        $logicLower = strtolower($logic);

        if (!in_array($logicLower, $this->supportedFilterLogics)) {
            throw new InvalidArgumentException(sprintf('Filter logic %s is not supported, Availables: %s', $logic, implode(', ', $this->supportedFilterLogics)));
        }

        $this->queryData['filters'][] = $filter;
        return $this;
    }

    public function find(): Response
    {
        try {
            $result = $this->client->request(
                    'GET',
                    sprintf(Config::API_PATH_MASK, $this->getMethod()),
                    [
                        'query' => array_merge($this->queryData, ['accessToken' => Config::getAccessToken()]),
                    ]
            );

            $this->onResponse();

            return Response::create(json_decode((string) $result->getBody()), $this->getModelClass());
        } catch (ClientException $ex) {
            throw new InvalidArgumentException($ex->getMessage(), $ex->getCode(), $ex);
        }
    }

    public function get(string $name): Response
    {
        try {
            $result = $this->client->request(
                    'GET',
                    sprintf(Config::API_PATH_MASK, sprintf($this->getPathMask(), $name)),
                    [
                        'query' => array_merge($this->queryData, ['accessToken' => Config::getAccessToken()]),
                    ]
            );

            $this->onResponse();

            return Response::create(json_decode((string) $result->getBody()), $this->getModelClass());
        } catch (ClientException $ex) {
            throw new InvalidArgumentException($ex->getMessage(), $ex->getCode(), $ex);
        }
    }

    public function post(): Response
    {
        try {
            $result = $this->client->request(
                    'POST',
                    sprintf(Config::API_PATH_MASK, $this->getMethod()),
                    [
                        'form_params' => $this->attributes,
                        'query' => array_merge($this->queryData, ['accessToken' => Config::getAccessToken()]),
                    ]
            );

            $this->onResponse();

            return Response::create(json_decode((string) $result->getBody()), $this->getModelClass());
        } catch (ClientException $ex) {
            throw new InvalidArgumentException($ex->getMessage(), $ex->getCode(), $ex);
        }
    }

    public function put(string $name): Response
    {
        try {
            $result = $this->client->request(
                    'PUT',
                    sprintf(Config::API_PATH_MASK, sprintf($this->getPathMask(), $name)),
                    [
                        'form_params' => $this->attributes,
                        'query' => array_merge($this->queryData, ['accessToken' => Config::getAccessToken()]),
                    ]
            );

            $this->onResponse();

            return Response::create(json_decode((string) $result->getBody()), $this->getModelClass());
        } catch (ClientException $ex) {
            throw new InvalidArgumentException($ex->getMessage(), $ex->getCode(), $ex);
        }
    }

    private function onResponse(): void
    {
        $this->attributes = [];
        $this->queryData = [];
    }

}
