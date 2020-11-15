<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Http;

use Daktela\DaktelaV6\Response\Response;
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
        $key = md5($baseUrl.$accessToken);
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
     * @return Response the resulting response of the sent request
     */
    public function sendRequest(
        string $method,
        string $apiEndpoint,
        array $queryParams = [],
        ?array $data = null
    ): Response {
        $queryParams['accessToken'] = $this->accessToken;

        //Initialize the HTTP client
        $client = new \GuzzleHttp\Client(
            [
                'base_uri' => self::normalizeUrl($this->baseUrl),
                'timeout' => 2.0,
                'verify' => self::VERIFY_SSL,
            ]
        );
        $headers = ["User-Agent" => self::USER_AGENT, "Content-Type" => "application/json"];

        //Prepare request URI
        $requestUri = self::API_NAMESPACE.lcfirst($apiEndpoint).".json?".http_build_query($queryParams);

        //Build the request
        $body = null;
        if (!is_null($data)) {
            $body = Utils::jsonEncode($data);
        }
        $request = new Request($method, $requestUri, $headers, $body);

        //Send the request
        $httpResponse = null;
        try {
            $httpResponse = $client->send($request);
        } catch (GuzzleException $ex) {
            return new Response(
                null,
                -1,
                [$ex->getMessage()],
                !is_null($httpResponse) ? $httpResponse->getStatusCode() : 0
            );
        }

        //Handle JSON parsing and result return
        $responseBody = $httpResponse->getBody()->getContents();
        if (mb_strlen($responseBody)) {
            try {
                $responseBody = Utils::jsonDecode($responseBody);
            } catch (InvalidArgumentException $ex) {
                return new Response(null, -1, [$ex->getMessage()], $httpResponse->getStatusCode());
            }
        }

        $data = $responseBody->result->data ?? ($responseBody->result ?? null);
        $total = $responseBody->result->total ?? (isset($responseBody->result) ? 1 : -1);
        $errors = $responseBody->error ?? [];

        return new Response($data, $total, $errors, $httpResponse->getStatusCode());
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

        if ((mb_substr($url, 0, 7) != "http://") && (mb_substr($url, 0, 8) != "https://")) {
            $url = "https://".$url;
        }
        if (mb_substr($url, -1) == "/") {
            $url = mb_substr($url, 0, -1);
        }

        return $url;
    }
}
