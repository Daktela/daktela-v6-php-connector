<?php
declare(strict_types=1);

namespace Daktela\DaktelaV6\Request;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Utils;
use Daktela\DaktelaV6\Response\Response;

abstract class ARequest
{
    const API_NAMESPACE = "/api/v6/";
    const USER_AGENT = "daktela-v6-php-connector";
    const VERIFY_SSL = false;
    private $instance = null;
    private $accessToken = null;
    private $executed = false;
    private $response = null;

    public function __construct($instance, $accessToken)
    {
        $this->instance = $instance;
        $this->accessToken = $accessToken;
    }

    protected function sendRequest(string $method, string $apiEndpoint, array $queryParams = [], ?array $data = null): Response
    {
        $queryParams['accessToken'] = $this->accessToken;

        //Initialize the HTTP client
        $client = new Client([
            'base_uri' => $this->normalizeUrl($this->instance),
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

    public function normalizeUrl(?string $url): ?string
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

    protected abstract function executeRequest(): Response;

    public function execute(): Response
    {
        if (!$this->executed) {
            $this->response = $this->executeRequest();
        }
        $this->executed = true;
        if (!is_null($this->response)) {
            return $this->response;
        }
        return new Response(null, 0, [], 0);
    }
}