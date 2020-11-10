<?php
declare(strict_types=1);

namespace Daktela\DaktelaV6\Http;

use Daktela\DaktelaV6\Client;
use Daktela\DaktelaV6\Response\Response;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Utils;

class ApiCommunicator
{
    const API_NAMESPACE = "/api/v6/";
    const USER_AGENT = "daktela-v6-php-connector";
    const VERIFY_SSL = false;
    private static $singletons = [];
    private $baseUrl;
    private $accessToken;

    public function __construct($baseUrl, $accessToken) {
        $this->baseUrl = $baseUrl;
        $this->accessToken = $accessToken;
    }

    public static function getInstance($baseUrl, $accessToken)
    {
        $key = md5($baseUrl . $accessToken);
        if (!isset(self::$singletons[$key])) {
            self::$singletons[$key] = new ApiCommunicator($baseUrl, $accessToken);
        }
        return self::$singletons[$key];
    }

    public function sendRequest(string $method, string $apiEndpoint, array $queryParams = [], ?array $data = null): Response
    {
        $queryParams['accessToken'] = $this->accessToken;

        //Initialize the HTTP client
        $client = new \GuzzleHttp\Client([
            'base_uri' => self::normalizeUrl($this->baseUrl),
            'timeout' => 2.0,
            'verify' => self::VERIFY_SSL
        ]);
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
        $httpResponse = null;
        try {
            $httpResponse = $client->send($request);
        } catch (GuzzleException $ex) {
            return new Response(null, -1, [$ex->getMessage()], !is_null($httpResponse) ? $httpResponse->getStatusCode() : 0);
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
        $errors = $responseBody->errors ?? [];

        return new Response($data, $total, $errors, $httpResponse->getStatusCode());
    }

    public static function normalizeUrl(?string $url): ?string
    {
        if (is_null($url)) {
            return null;
        }

        if ((mb_substr($url, 0, 7) != "http://") && (mb_substr($url, 0, 8) != "https://")) {
            $url = "https://" . $url;
        }
        if (mb_substr($url, -1) == "/") {
            $url = mb_substr($url, 0, -1);
        }
        return $url;
    }
}