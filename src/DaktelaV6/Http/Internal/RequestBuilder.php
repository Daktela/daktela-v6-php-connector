<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Http\Internal;

use Daktela\DaktelaV6\Exception\InvalidRequestException;
use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\Http\ApiCommunicator;
use GuzzleHttp\Psr7\Request;

/**
 * Builds the PSR-7 requests of the connector: the request target under the instance, the authentication, and
 * the payload, including the credentials of a login or token refresh.
 * @internal
 */
final class RequestBuilder
{
    /** @var string Constant defining the base API URL */
    private const API_NAMESPACE = "/api/v6/";
    /** @var string Constant defining the User-Agent of the HTTP requests */
    private const USER_AGENT = "daktela-v6-php-connector";

    public function __construct(
        private readonly string $baseUrl,
        private readonly Credentials $credentials,
        private readonly Redactor $redactor,
        private readonly int $authenticationMethod,
        private readonly ?string $userAgentSuffix
    ) {
    }

    /**
     * Build the PSR-7 request object.
     *
     * @param string $method HTTP method
     * @param string $apiEndpoint API endpoint
     * @param array $queryParams Query parameters
     * @param array|null $data Request body data
     * @param bool $authenticate whether to send the access token; false for a login or token refresh request,
     *     whose payload is built here from the login session
     * @return Request PSR-7 request object
     */
    public function build(
        string $method,
        string $apiEndpoint,
        array $queryParams,
        #[\SensitiveParameter] ?array $data,
        bool $authenticate
    ): Request {
        $userAgent = self::USER_AGENT;
        if ($this->userAgentSuffix !== null) {
            $userAgent .= ' ' . $this->userAgentSuffix;
        }
        $headers = ["User-Agent" => $userAgent, "Content-Type" => "application/json"];

        if (
            $authenticate
            && $this->authenticationMethod !== ApiCommunicator::AUTHENTICATION_METHOD_QUERY
            && preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $this->credentials->accessToken()) === 1
        ) {
            // Not a valid header value: Guzzle's error would quote the header value, trimmed, so redact() could miss it
            throw new InvalidRequestException('The access token contains a line break or another control character.');
        }
        // A copy: the trace of an exception created here records the parameters, which must not get the token
        $query = $queryParams;
        if (!$authenticate) {
            // login and token refresh requests carry their credentials in the payload
        } elseif ($this->authenticationMethod === ApiCommunicator::AUTHENTICATION_METHOD_QUERY) {
            $query['accessToken'] = $this->credentials->accessToken();
        } elseif ($this->authenticationMethod === ApiCommunicator::AUTHENTICATION_METHOD_BEARER) {
            $headers['Authorization'] = 'Bearer ' . $this->credentials->accessToken();
        } else {
            $headers['X-AUTH-TOKEN'] = $this->credentials->accessToken();
        }

        // An instance URL that already ends with the API namespace is accepted, as before
        $basePath = rtrim((string)parse_url((string)InstanceUrl::normalize($this->baseUrl), PHP_URL_PATH), '/');
        $basePath = (string)preg_replace('#/api/v6$#i', '', $basePath);
        // A request target that starts with `//` names another host, which would get the credentials. The slashes
        // are removed after the API namespace: `//api/v6` leaves `/`, which would start the target with `//api`
        $basePath = trim($basePath, '/');
        $basePath = $basePath === '' ? '' : '/' . $basePath;
        // The separator is given because http_build_query() otherwise uses the arg_separator.output ini setting
        $requestUri = $basePath . self::API_NAMESPACE . lcfirst($apiEndpoint) . ".json?" . http_build_query($query, '', '&');
        try {
            // A local variable: the credentials of a login must not become the value of a parameter
            $payload = $authenticate ? $data : $this->credentialsPayload($apiEndpoint);
            $body = $payload !== null ? json_encode($payload, JSON_THROW_ON_ERROR) : null;
            $payload = null;
        } catch (\JsonException $ex) {
            // e.g. a string that is not valid UTF-8; the message does not quote the payload, and the exception,
            // whose trace holds the payload as an argument, is not chained
            throw new InvalidRequestException('The request payload cannot be encoded as JSON: json_encode error: ' . $ex->getMessage());
        }

        try {
            // Guzzle 8 sends the method as given, Guzzle 7 in upper case
            $request = new Request(strtoupper($method), $requestUri, $headers, $body);
        } catch (\InvalidArgumentException $ex) {
            // An invalid header value, e.g. an access token with a trailing newline, is quoted in the message
            throw new RequestException($this->redactor->redact($ex->getMessage()));
        }
        if ($request->getUri()->getHost() !== '' || $request->getUri()->getScheme() !== '') {
            // The request is a path the HTTP client resolves against the instance; one that names a host of its
            // own would take the credentials there
            throw new InvalidRequestException('The request would not be sent to the instance; check the instance URL.');
        }

        return $request;
    }

    /**
     * @param string $apiEndpoint login or refreshAccessToken
     * @return array payload of a login or token refresh request, built from the login session
     */
    private function credentialsPayload(string $apiEndpoint): array
    {
        $session = $this->credentials->session();
        if ($apiEndpoint === 'refreshAccessToken') {
            return ['refresh_token' => $session?->tokens?->getRefreshToken() ?? ''];
        }
        if ($apiEndpoint !== 'login') {
            // No other request is sent without the access token; it must not get the credentials
            throw new \LogicException('Only login and refreshAccessToken requests carry credentials.');
        }

        return [
            'username' => $session === null ? '' : $session->username,
            'password' => $session === null ? '' : $session->password,
            // the tokens are returned instead of being set as cookies
            'only_token' => 'true',
            // the connector refreshes the session itself, the server does not renew it implicitly
            'client_side_refresh' => 'true',
        ];
    }
}
