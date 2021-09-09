<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Http;

use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\Response\Response;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Utils;

/**
 * Class ApiCommunicator is a transport class of the Daktela V6 communication package and
 * performs the main HTTP operations necessary to perform actions onto Daktela V6 API.
 * @package Daktela\DaktelaV6\Http
 */
class ApiCommunicator
{
    /** @var string Constant defining the base API URL */
    private const API_NAMESPACE = "/api/v6/";
    /** @var string Constant defining the User-Agent of the HTTP requests */
    private const USER_AGENT = "daktela-v6-php-connector";
    /** @var bool Constant defining where SSL certificate needs to be trusted */
    private const VERIFY_SSL = true;
    /** @var array static variable containing all singleton instances of the transport class */
    private static $singletons = [];
    /** @var string URL of the Daktela instance */
    private $baseUrl;
    /** @var string Access token used for communicating with Daktela REST API */
    private $accessToken;
    /** @var float Timeout for HTTP request sent to API */
    private $requestTimeout = 2.0;

    /**
     * ApiCommunicator constructor.
     * @param string $baseUrl URL of the Daktela instance
     * @param string $accessToken access token of user used for connecting to Daktela V6
     */
    public function __construct(string $baseUrl, string $accessToken)
    {
        $this->baseUrl = $baseUrl;
        $this->accessToken = $accessToken;
    }

    /**
     * Static method for using ApiCommunicator client connector as singleton.
     * @param string $baseUrl URL of the Daktela instance
     * @param string $accessToken access token of user used for connecting to Daktela V6
     * @return ApiCommunicator instance of the transport class
     */
    public static function getInstance(string $baseUrl, string $accessToken): self
    {
        $key = md5($baseUrl . $accessToken);
        if (!isset(self::$singletons[$key])) {
            self::$singletons[$key] = new ApiCommunicator($baseUrl, $accessToken);
        }

        return self::$singletons[$key];
    }

    /**
     * Method for sending the requested data to Daktela API using HTTP client.
     * @param string $method requested HTTP method for the request (GET/POST/PUT/DELETE)
     * @param string $apiEndpoint requested API endpoint based on the Daktela V6 API documentation
     * @param array $queryParams query parameters to be sent as part of the URL
     * @param array|null $data collection of data to be sent as request payload (or null when none)
     * @return Response the resulting response of the request sent
     * @throws RequestException request exception with details
     */
    public function sendRequest(
        string $method,
        string $apiEndpoint,
        array $queryParams = [],
        ?array $data = null
    ): Response {
        $queryParams['accessToken'] = $this->accessToken;

        //Initialize the HTTP client
        $client = new Client(
            [
                'base_uri' => self::normalizeUrl($this->baseUrl),
                'timeout' => $this->requestTimeout,
                'verify' => self::VERIFY_SSL,
            ]
        );
        $headers = ["User-Agent" => self::USER_AGENT, "Content-Type" => "application/json"];

        //Prepare request URI
        $requestUri = self::API_NAMESPACE . lcfirst($apiEndpoint) . ".json?" . http_build_query($queryParams);

        //Build the request
        $body = null;
        if (!is_null($data)) {
            $body = Utils::jsonEncode($data);
        }
        $request = new Request($method, $requestUri, $headers, $body);

        //Send the request
        try {
            $httpResponse = $client->send($request);
        } catch (GuzzleException $ex) {
            throw new RequestException($ex->getMessage(), $ex->getCode(), $ex);
        }

        //Handle JSON parsing and result return
        $responseBody = $httpResponse->getBody()->getContents();
        if (mb_strlen($responseBody)) {
            try {
                $responseBody = Utils::jsonDecode($responseBody);
            } catch (InvalidArgumentException $ex) {
                throw new RequestException($ex->getMessage(), $ex->getCode(), $ex);
            }
        }

        if (!isset($responseBody->result)) {
            return new Response(null, 0, [], $httpResponse->getStatusCode());
        }

        $data = $responseBody->result->data ?? ($responseBody->result ?? null);
        $total = $responseBody->result->total ?? 1;
        $errors = !isset($responseBody->error) ? [] : $responseBody->error;

        return new Response($data, $total, $errors, $httpResponse->getStatusCode());
    }

    /**
     * The HTTP request timeout that should be used when communicating with the associated API.
     * @param float $requestTimeout Timeout of the HTTP request
     * @noinspection PhpPhpUnused
     */
    public function setRequestTimeout(float $requestTimeout): void
    {
        $this->requestTimeout = $requestTimeout;
    }

    /**
     * Method for normalizing URL into one standard form the API transport class can use as part of the HTTP request.
     * @param string|null $url URL to be normalized
     * @return string|null normalized URL
     */
    public static function normalizeUrl(?string $url): ?string
    {
        if (is_null($url)) {
            return null;
        }

        /** @noinspection HttpUrlsUsage */
        if ((mb_substr($url, 0, 7) != "http://") && (mb_substr($url, 0, 8) != "https://")) {
            $url = "https://" . $url;
        }
        if (mb_substr($url, -1) == "/") {
            $url = mb_substr($url, 0, -1);
        }

        return $url;
    }
}
