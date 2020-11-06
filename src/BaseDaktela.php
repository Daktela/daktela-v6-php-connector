<?php

declare(strict_types=1);

namespace Daktela;

use Cake\Log\Log;
use Daktela\Request\IRequest;
use Daktela\Response\Response;
use GuzzleHttp\Client;
use InvalidArgumentException;
use UnexpectedValueException;
use function count;
use function json_decode;
use function json_encode;

/**
 * @author Petr Kalíšek <petr.kalisek@daktela.com>
 */
abstract class BaseDaktela implements IRequest
{

    const DIRECTION_ASC = 'asc';
    const DIRECTION_DESC = 'desc';

    const FILTER_LOGIC_OR = 'or';
    const FILTER_LOGIC_AND = 'and';
    const FILTER_OPERATOR_EQ = 'eq';

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
    private $supportedFilterLogics = [self::FILTER_LOGIC_OR, self::FILTER_LOGIC_AND];


    /**
     * @var Client|null
     */
    private $client = null;

    public function __construct(array $config = array())
    {
        $config['base_uri'] = self::getInstanceHost();

        $this->client = new Client($config);


        $this->queryData['accessToken'] = Config::getAccessToken();
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

    public function setFilter(array $filter, string $logic = self::FILTER_LOGIC_AND): IRequest
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
        $result = $this->client->request(
                'GET',
                sprintf(Config::API_PATH_MASK, $this->getMethod()),
                [
                    'debug' => true,
                    'query' => $this->queryData,
                ]
        );

        return Response::create(json_decode((string) $result->getBody()), $this->getModelClass());
    }

    public function get(string $name): Response
    {
        $result = $this->client->request(
                'GET',
                sprintf(Config::API_PATH_MASK, sprintf($this->getPathMask(), $name)),
                [
                    'debug' => true,
                    'query' => $this->queryData,
                ]
        );

        return Response::create(json_decode((string) $result->getBody()), $this->getModelClass());
    }

    /*
      public function getResult(): Response
      {

      $result = $this->request(
      'GET',
      $this->getUrlPath(),
      [
      'query' => ['accessToken' => Config::getAccessToken(),
      'skip' => $this->skip,
      'take' => $this->limit,
      'sort' => $this->sort,
      ],
      'debug' => true,

      ]
      );


      var_dump((string) $result->getBody());

      echo "<hr/>";

      var_dump($this);
      if ($this->result === null) {

      }
      $this->result;
      } */

    public static function getInstanceHost(): string
    {
        return Config::getInstanceUri();
    }

    /**
     * @deprecated refactor to post() method
     *
     * @param string $method
     * @param array $data
     * @param array $additional
     * @return Response|null
     * @throws UnexpectedValueException
     
    public static function postData(string $method, array $data = [], array $additional = []): ?Response
    {
        $response = V6::postData(self::getInstanceHost(), Config::getAccessToken(), $method, $data, $additional);

        if (property_exists($response, 'error') && !empty($response->error)) {
            //is_array($response->error) && count($response->error) > 0) {
            throw new UnexpectedValueException(json_encode($response->error));
        }

        return ($response === false ? null : Response::create($response));
    }
     */
    /**
     * @deprecated refactor to get() method
     *
     * @param string $method
     * @param array $filter
     * @param array $additional
     * @param bool $countOnly
     * @return Response|null
     * @throws UnexpectedValueException
     
    public static function getJsonData(string $method, array $filter = [], array $additional = [], bool $countOnly = false): ?Response
    {
        $response = V6::getData(self::getInstanceHost(), DaktelaConfig::getAccessToken(), $method, $filter, $additional, $countOnly);

        Log::info($response);

        if (is_array($response)) {
            if (count($response) > 1) {
                throw new UnexpectedValueException('Not completed ... result array with more items (is collection needed)');
            }

            if (isset($response[0])) {
                $response = $response[0];
            }
        }

        if (($response !== false) && property_exists($response, 'error') && !empty($response->error)) {
//        if (isset($response->error) && is_array($response->error) && count($response->error) > 0) {
            throw new UnexpectedValueException(json_encode($response->error));
        }

        return ($response === false ? null : Response::create($response));
    }
     */
    /**
     * @deprecated refactor to put() method
     *
     * @param string $method
     * @param array $data
     * @param array $additional
     * @return Response|null
     
    public static function putData(string $method, array $data = [], array $additional = []): ?Response
    {
        $response = V6::putData(self::getInstanceHost(), DaktelaConfig::getAccessToken(), $method, $data, $additional);
        if (property_exists($response, 'error') && !empty($response->error)) {
//        if (is_array($response->error) && count($response->error) > 0) {
            throw new UnexpectedValueException(json_encode($response->error));
        }

        return ($response === false ? null : Response::create($response));
    }
     */
}
