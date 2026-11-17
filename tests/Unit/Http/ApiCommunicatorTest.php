<?php

declare(strict_types=1);

namespace Daktela\Tests\Unit\Http;

use Daktela\DaktelaV6\Exception\NotFoundException;
use Daktela\DaktelaV6\Exception\RateLimitException;
use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\Http\ApiCommunicator;
use Daktela\DaktelaV6\Http\RateLimitConfig;
use Daktela\DaktelaV6\Http\RetryConfig;
use Daktela\Tests\Support\Guzzle7StrictClientException;
use Daktela\Tests\Support\GuzzleExceptions;
use Daktela\Tests\Support\StrictRequestException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\Psr7\Utils as Psr7Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;

class ApiCommunicatorTest extends TestCase
{
    private function getPrivateProperty(object $object, string $name): mixed
    {
        $property = new \ReflectionProperty($object, $name);
        return $property->getValue($object);
    }

    /**
     * @param array|null $history receives every request that reaches the mock handler, including redirect hops
     * @param string|null $baseUri base URI of the client, which makes the request URIs absolute
     */
    private function createMockClient(array $responses, ?array &$history = null, ?string $baseUri = null): Client
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        if ($history !== null) {
            $handlerStack->push(Middleware::history($history));
        }
        return new Client(array_filter(['handler' => $handlerStack, 'base_uri' => $baseUri]));
    }

    public function testConstructor(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token123');

        $this->assertInstanceOf(ApiCommunicator::class, $communicator);
    }

    public function testGetInstanceReturnsSameInstance(): void
    {
        // Clear singleton cache by using unique credentials
        $uniqueUrl = 'https://test' . uniqid() . '.com';

        $instance1 = ApiCommunicator::getInstance($uniqueUrl, 'token1');
        $instance2 = ApiCommunicator::getInstance($uniqueUrl, 'token1');

        $this->assertSame($instance1, $instance2);
    }

    public function testGetInstanceReturnsDifferentInstanceForDifferentCredentials(): void
    {
        $uniqueUrl = 'https://test' . uniqid() . '.com';

        $instance1 = ApiCommunicator::getInstance($uniqueUrl, 'token1');
        $instance2 = ApiCommunicator::getInstance($uniqueUrl, 'token2');

        $this->assertNotSame($instance1, $instance2);
    }

    public function testSetVerifySsl(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $result = $communicator->setVerifySsl(false);

        $this->assertSame($communicator, $result);
    }

    public function testSetUserAgentSuffix(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $result = $communicator->setUserAgentSuffix('MyApp/1.0');

        $this->assertSame($communicator, $result);
    }

    public function testSetUserAgentSuffixNull(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setUserAgentSuffix('MyApp/1.0');
        $result = $communicator->setUserAgentSuffix(null);

        $this->assertSame($communicator, $result);
    }

    public function testSetLogger(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $logger = $this->createMock(LoggerInterface::class);
        $result = $communicator->setLogger($logger);

        $this->assertSame($communicator, $result);
    }

    public function testSetHttpClient(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $client = $this->createMockClient([]);
        $result = $communicator->setHttpClient($client);

        $this->assertSame($communicator, $result);
    }

    public function testSetHttpClientNull(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $result = $communicator->setHttpClient(null);

        $this->assertSame($communicator, $result);
    }

    public function testSetRetryConfig(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $config = new RetryConfig();
        $result = $communicator->setRetryConfig($config);

        $this->assertSame($communicator, $result);
    }

    public function testSetRateLimitConfig(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $config = new RateLimitConfig();
        $result = $communicator->setRateLimitConfig($config);

        $this->assertSame($communicator, $result);
    }

    public function testSetRequestTimeout(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setRequestTimeout(30.0);

        $this->assertSame(30.0, $this->getPrivateProperty($communicator, 'requestTimeout'));
    }

    public function testDefaultClientUsesConfiguredTransportOptions(): void
    {
        $communicator = new ApiCommunicator('example.com/', 'token');
        $communicator->setRequestTimeout(12.5);
        $communicator->setVerifySsl(false);
        $method = new \ReflectionMethod($communicator, 'defaultClient');

        /** @var Client $client */
        $client = $method->invoke($communicator);

        $this->assertSame('https://example.com', (string)$client->getConfig('base_uri'));
        $this->assertSame(12.5, $client->getConfig('timeout'));
        $this->assertFalse($client->getConfig('verify'));
    }

    public function testDefaultAuthenticationIsBearerHeader(): void
    {
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'secret-token');
        $communicator->setHttpClient($this->createMockClient([$this->okResponse()], $history));

        $communicator->sendRequest('GET', 'Users');

        $request = $history[0]['request'];
        $this->assertSame('Bearer secret-token', $request->getHeaderLine('Authorization'));
        $this->assertFalse($request->hasHeader('X-AUTH-TOKEN'));
    }

    public function testSetAuthenticationMethodHeader(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setAuthenticationMethod(ApiCommunicator::AUTHENTICATION_METHOD_HEADER);

        $this->assertSame(
            ApiCommunicator::AUTHENTICATION_METHOD_HEADER,
            $this->getPrivateProperty($communicator, 'authenticationMethod')
        );
    }

    public function testSetAuthenticationMethodQuery(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setAuthenticationMethod(ApiCommunicator::AUTHENTICATION_METHOD_QUERY);

        $this->assertSame(
            ApiCommunicator::AUTHENTICATION_METHOD_QUERY,
            $this->getPrivateProperty($communicator, 'authenticationMethod')
        );
    }

    public function testSetAuthenticationMethodBearer(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setAuthenticationMethod(ApiCommunicator::AUTHENTICATION_METHOD_BEARER);

        $this->assertSame(
            ApiCommunicator::AUTHENTICATION_METHOD_BEARER,
            $this->getPrivateProperty($communicator, 'authenticationMethod')
        );
    }

    public function testSetAuthenticationMethodInvalid(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Invalid authentication method');

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setAuthenticationMethod(999);
    }

    public function testNormalizeUrlAddsHttps(): void
    {
        $result = ApiCommunicator::normalizeUrl('example.com');

        $this->assertEquals('https://example.com', $result);
    }

    public function testNormalizeUrlRemovesTrailingSlash(): void
    {
        $result = ApiCommunicator::normalizeUrl('https://example.com/');

        $this->assertEquals('https://example.com', $result);
    }

    public function testNormalizeUrlPreservesHttps(): void
    {
        $result = ApiCommunicator::normalizeUrl('https://example.com');

        $this->assertEquals('https://example.com', $result);
    }

    public function testNormalizeUrlPreservesHttp(): void
    {
        $result = ApiCommunicator::normalizeUrl('http://example.com');

        $this->assertEquals('http://example.com', $result);
    }

    public function testNormalizeUrlHandlesNull(): void
    {
        $result = ApiCommunicator::normalizeUrl(null);

        $this->assertNull($result);
    }

    public function testSendRequestSuccess(): void
    {
        $mockClient = $this->createMockClient([
            new GuzzleResponse(200, [], json_encode([
                'result' => ['data' => [['id' => 1]], 'total' => 1],
            ])),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);

        $response = $communicator->sendRequest('GET', 'Users');

        $this->assertTrue($response->isSuccess());
        $this->assertEquals(200, $response->getHttpStatus());
    }

    public function testSendRequestNoResult(): void
    {
        $mockClient = $this->createMockClient([
            new GuzzleResponse(200, [], json_encode(['other' => 'data'])),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);

        $response = $communicator->sendRequest('GET', 'Users');

        $this->assertNull($response->getData());
        $this->assertEquals(0, $response->getTotal());
    }

    public function testSendRequestPreservesExceptionForNonRetryableHttpErrors(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('400 Bad Request');

        $mockClient = $this->createMockClient([
            new GuzzleResponse(400, [], json_encode([
                'result' => ['data' => null],
                'error' => ['Invalid request'],
            ])),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);

        $communicator->sendRequest('GET', 'Users');
    }

    public function testSendRequestParsesErrorsFromSuccessfulApiResponse(): void
    {
        $mockClient = $this->createMockClient([
            new GuzzleResponse(200, [], json_encode([
                'result' => ['data' => null],
                'error' => ['Invalid request'],
            ])),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);

        $response = $communicator->sendRequest('GET', 'Users');

        $this->assertSame(['Invalid request'], $response->getErrors());
        $this->assertTrue($response->hasErrors());
    }

    public function testSendRequestRetryOnConnectionError(): void
    {
        $mockClient = $this->createMockClient([
            new ConnectException('Connection failed', new Request('GET', '/')),
            new GuzzleResponse(200, [], json_encode([
                'result' => ['data' => [], 'total' => 0],
            ])),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);
        $communicator->setRetryConfig(new RetryConfig(maxRetries: 1, baseDelayMs: 1));

        $response = $communicator->sendRequest('GET', 'Users');

        $this->assertTrue($response->isSuccess());
    }

    public function testSendRequestNoRetryWithoutConfig(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Connection failed');

        $mockClient = $this->createMockClient([
            new ConnectException('Connection failed', new Request('GET', '/')),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);

        $communicator->sendRequest('GET', 'Users');
    }

    public function testSendRequestWrapsOtherGuzzleExceptions(): void
    {
        $guzzleException = new GuzzleRequestException(
            'Transport failed',
            new Request('GET', 'https://example.com')
        );
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('send')
            ->willThrowException($guzzleException);
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($httpClient);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected the transport exception to be wrapped');
        } catch (RequestException $exception) {
            $this->assertSame('Transport failed', $exception->getMessage());
            // A copy without the access token is chained instead of the client's exception
            $this->assertInstanceOf(GuzzleRequestException::class, $exception->getPrevious());
            $this->assertSame('Transport failed', $exception->getPrevious()->getMessage());
            $this->assertSame('https://example.com', (string)$exception->getPrevious()->getRequest()->getUri());
        }
    }

    public function testSendRequestRetryOnServerError(): void
    {
        $mockClient = $this->createMockClient([
            new GuzzleResponse(500, [], ''),
            new GuzzleResponse(200, [], json_encode([
                'result' => ['data' => [], 'total' => 0],
            ])),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);
        $communicator->setRetryConfig(new RetryConfig(maxRetries: 1, baseDelayMs: 1));

        $response = $communicator->sendRequest('GET', 'Users');

        $this->assertTrue($response->isSuccess());
    }

    public function testSendRequestRateLimitThrowsWithoutConfig(): void
    {
        $this->expectException(RateLimitException::class);

        $mockClient = $this->createMockClient([
            new GuzzleResponse(429, ['Retry-After' => '10'], ''),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);

        $communicator->sendRequest('GET', 'Users');
    }

    public function testSendRequestAutomaticallyRetriesRateLimitWithoutRetryConfig(): void
    {
        $mockClient = $this->createMockClient([
            new GuzzleResponse(429, ['Retry-After' => '0'], ''),
            new GuzzleResponse(200, [], json_encode([
                'result' => ['data' => [['id' => 1]], 'total' => 1],
            ])),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);
        $communicator->setRateLimitConfig(new RateLimitConfig(defaultWaitSeconds: 0));

        $response = $communicator->sendRequest('GET', 'Users');

        $this->assertTrue($response->isSuccess());
        $this->assertSame(1, $response->getTotal());
    }

    public function testSendRequestRateLimitThrowsWhenAutoRetryDisabled(): void
    {
        $mockClient = $this->createMockClient([
            new GuzzleResponse(429, ['Retry-After' => '7'], ''),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);
        $communicator->setRateLimitConfig(new RateLimitConfig(autoRetry: false));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected a rate-limit exception');
        } catch (RateLimitException $exception) {
            $this->assertSame(7, $exception->getRetryAfterSeconds());
        }
    }

    public function testSendRequestRateLimitThrowsWhenWaitExceedsMaximum(): void
    {
        $mockClient = $this->createMockClient([
            new GuzzleResponse(429, ['Retry-After' => '2'], ''),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);
        $communicator->setRateLimitConfig(new RateLimitConfig(maxWaitSeconds: 1));

        $this->expectException(RateLimitException::class);
        $communicator->sendRequest('GET', 'Users');
    }

    public function testSendRequestStopsAfterAvailableRateLimitRetry(): void
    {
        $mockClient = $this->createMockClient([
            new GuzzleResponse(429, ['Retry-After' => '0'], ''),
            new GuzzleResponse(429, ['Retry-After' => '0'], ''),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);
        $communicator->setRateLimitConfig(new RateLimitConfig(defaultWaitSeconds: 0));

        $this->expectException(RateLimitException::class);
        $communicator->sendRequest('GET', 'Users');
    }

    public function testBuildsHeaderAuthenticatedJsonRequest(): void
    {
        $history = [];
        $mockClient = $this->createMockClient([
            new GuzzleResponse(200, [], json_encode(['result' => ['data' => null]])),
        ], $history);

        $communicator = new ApiCommunicator('https://example.com', 'secret-token');
        $communicator->setAuthenticationMethod(ApiCommunicator::AUTHENTICATION_METHOD_HEADER);
        $communicator->setUserAgentSuffix('Example/1.0');
        $communicator->setHttpClient($mockClient);
        $communicator->sendRequest('POST', 'Users', ['take' => 5], ['name' => 'Alice']);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/v6/users.json', $request->getUri()->getPath());
        $this->assertSame('take=5', $request->getUri()->getQuery());
        $this->assertSame('secret-token', $request->getHeaderLine('X-AUTH-TOKEN'));
        $this->assertSame(
            'daktela-v6-php-connector Example/1.0',
            $request->getHeaderLine('User-Agent')
        );
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('{"name":"Alice"}', (string)$request->getBody());
    }

    public function testBuildsQueryAuthenticatedRequest(): void
    {
        $history = [];
        $mockClient = $this->createMockClient([
            new GuzzleResponse(200, [], json_encode(['result' => ['data' => null]])),
        ], $history);

        $communicator = new ApiCommunicator('https://example.com', 'secret-token');
        $communicator->setAuthenticationMethod(ApiCommunicator::AUTHENTICATION_METHOD_QUERY);
        $communicator->setHttpClient($mockClient);
        $communicator->sendRequest('GET', 'Users', ['take' => 5]);

        $request = $history[0]['request'];
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame(['take' => '5', 'accessToken' => 'secret-token'], $query);
        $this->assertFalse($request->hasHeader('X-AUTH-TOKEN'));
    }

    public function testBuildsBearerAuthenticatedRequest(): void
    {
        $history = [];
        $mockClient = $this->createMockClient([
            new GuzzleResponse(200, [], json_encode(['result' => ['data' => null]])),
        ], $history);

        $communicator = new ApiCommunicator('https://example.com', 'secret-token');
        $communicator->setAuthenticationMethod(ApiCommunicator::AUTHENTICATION_METHOD_BEARER);
        $communicator->setHttpClient($mockClient);
        $communicator->sendRequest('GET', 'Users', ['take' => 5]);

        $request = $history[0]['request'];
        $this->assertSame('Bearer secret-token', $request->getHeaderLine('Authorization'));
        $this->assertFalse($request->hasHeader('X-AUTH-TOKEN'));
        $this->assertSame('take=5', $request->getUri()->getQuery());
    }

    public function testBearerAuthenticationKeepsTokenOutOfErrors(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setAuthenticationMethod(ApiCommunicator::AUTHENTICATION_METHOD_BEARER);
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(401, [], json_encode(['error' => ['Token SECRET is not valid']])),
        ]));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('RequestException was not thrown');
        } catch (RequestException $ex) {
            $this->assertSame(401, $ex->getHttpStatus());
            $this->assertStringNotContainsString('SECRET', $ex->getMessage());
            $this->assertStringNotContainsString('SECRET', (string)$ex->getResponseBody());
            $this->assertSame(['Token *** is not valid'], $ex->getApiErrors());
        }
    }

    public function testSendRequestRejectsInvalidJson(): void
    {
        $mockClient = $this->createMockClient([
            new GuzzleResponse(200, [], '{invalid-json'),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);

        $this->expectException(RequestException::class);
        $communicator->sendRequest('GET', 'Users');
    }

    public function testInvalidJsonExceptionCarriesHttpStatusAndRedactedBody(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(200, [], '<html>Hello SECRET</html>'),
        ]));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame(200, $ex->getHttpStatus());
            $this->assertSame('<html>Hello ***</html>', $ex->getResponseBody());
        }
    }

    public function testSendRequestThrowsAfterConnectionRetriesAreExhausted(): void
    {
        $mockClient = $this->createMockClient([
            new ConnectException('First failure', new Request('GET', '/')),
            new ConnectException('Second failure', new Request('GET', '/')),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);
        $communicator->setRetryConfig(new RetryConfig(maxRetries: 1, baseDelayMs: 0));

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Max retries exceeded: Second failure');
        $communicator->sendRequest('GET', 'Users');
    }

    public function testSendRequestDoesNotRetryConnectionWhenDisabled(): void
    {
        $mockClient = $this->createMockClient([
            new ConnectException('Connection failed', new Request('GET', '/')),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);
        $communicator->setRetryConfig(new RetryConfig(retryOnConnectionError: false));

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Connection failed');
        $communicator->sendRequest('GET', 'Users');
    }

    public function testPingReturnsTrue(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([
            $this->whoimResponse(['name' => 'api', 'title' => 'API user']),
        ]));

        $this->assertTrue($communicator->ping());
    }

    public function testPingReturnsFalseOnError(): void
    {
        $mockClient = $this->createMockClient([
            new GuzzleResponse(401, [], json_encode(['error' => 'Unauthorized'])),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);

        $this->assertFalse($communicator->ping());
    }

    public function testHealthCheckReturnsDetails(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([
            $this->whoimResponse(['name' => 'api', 'title' => 'API user']),
        ]));

        $result = $communicator->healthCheck();

        $this->assertTrue($result['healthy']);
        $this->assertArrayHasKey('latency_ms', $result);
        $this->assertSame(200, $result['status_code']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function testHealthCheckReturnsErrorOnFailure(): void
    {
        $mockClient = $this->createMockClient([
            new ConnectException('Connection refused', new Request('GET', '/')),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);

        $result = $communicator->healthCheck();

        $this->assertFalse($result['healthy']);
        $this->assertArrayHasKey('latency_ms', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('status_code', $result);
    }

    public function testHealthCheckReportsStatusCodeWhenRateLimited(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([new GuzzleResponse(429, ['Retry-After' => '7'])]));

        $result = $communicator->healthCheck();

        $this->assertFalse($result['healthy']);
        $this->assertSame(429, $result['status_code']);
    }

    public function testHealthCheckReportsStatusCodeForNonJsonResponse(): void
    {
        // e.g. a proxy's HTML error page received through a client that does not throw on HTTP errors
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient(new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new GuzzleResponse(401, [], '<html>Unauthorized</html>'),
            ])),
            'http_errors' => false,
        ]));

        $result = $communicator->healthCheck();

        $this->assertFalse($result['healthy']);
        $this->assertSame(401, $result['status_code']);
    }

    public function testHealthCheckRedactsTokenInApiErrorOfReceivedResponse(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(200, [], json_encode(['error' => ['Token SECRET expired'], 'result' => ['user' => null]])),
        ]));

        $this->assertSame('Token *** expired', $communicator->healthCheck()['error']);
    }

    public function testPingReturnsFalseWhenTokenIsNotAccepted(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([$this->whoimResponse(null)]));

        $this->assertFalse($communicator->ping());
    }

    public function testHealthCheckReportsTokenNotAcceptedWhenWhoimReturnsNoUser(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([$this->whoimResponse(null)]));

        $result = $communicator->healthCheck();

        $this->assertFalse($result['healthy']);
        $this->assertSame(200, $result['status_code']);
        $this->assertSame('The access token was not accepted: the API did not identify a user.', $result['error']);
    }

    public function testHealthCheckReportsApiErrorForPendingPasswordChange(): void
    {
        // The body as the API sends it: translated message with JSON-escaped non-ASCII characters
        // (json_encode() escapes them by default, as PHP does on the server)
        $body = json_encode([
            'error' => ['error' => 'Koniecznie ustaw nowe hasło'],
            'result' => null,
            '_serverTime' => '2026-05-28 13:32:25',
            '_time' => '2026-05-28 13:32:25',
            '_timezone' => 'Europe/Warsaw',
        ]);
        $this->assertStringContainsString('has\\u0142o', $body, 'The fixture must carry the escaped form the API sends');
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(400, [], $body),
            new GuzzleResponse(400, [], $body),
        ]));

        $result = $communicator->healthCheck();

        $this->assertFalse($result['healthy']);
        $this->assertSame(400, $result['status_code']);
        $this->assertSame('Koniecznie ustaw nowe hasło', $result['error']);
        $this->assertFalse($communicator->ping());
    }

    public function testHealthCheckUsesFirstNestedApiErrorMessage(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(400, [], json_encode(['error' => ['form' => ['password' => ['', 'Too short']]]])),
        ]));

        $this->assertSame('Too short', $communicator->healthCheck()['error']);
    }

    public function testHealthCheckFallsBackToExceptionMessageWithoutApiError(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setAuthenticationMethod(ApiCommunicator::AUTHENTICATION_METHOD_QUERY);
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(401, [], 'Unauthorized'),
        ]));

        $result = $communicator->healthCheck();

        $this->assertFalse($result['healthy']);
        $this->assertSame(401, $result['status_code']);
        $this->assertStringContainsString('401 Unauthorized', $result['error']);
        $this->assertStringNotContainsString('SECRET', $result['error']);
    }

    public function testHealthCheckReportsApiErrorWhenClientDoesNotThrowOnHttpErrors(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient(new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new GuzzleResponse(400, [], json_encode(['error' => ['error' => 'New password should be set'], 'result' => null])),
            ])),
            'http_errors' => false,
        ]));

        $result = $communicator->healthCheck();

        $this->assertFalse($result['healthy']);
        $this->assertSame(400, $result['status_code']);
        $this->assertSame('New password should be set', $result['error']);
    }

    public function testHealthCheckReportsHttpStatusForErrorResponseWithoutApiError(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient(new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new GuzzleResponse(503, [], json_encode(['message' => 'Service Unavailable'])),
            ])),
            'http_errors' => false,
        ]));

        $result = $communicator->healthCheck();

        $this->assertFalse($result['healthy']);
        $this->assertSame(503, $result['status_code']);
        $this->assertSame('The API answered HTTP 503 without an error message.', $result['error']);
    }

    public function testHealthCheckReportsUnexpectedResponseWithoutUserInformation(): void
    {
        // e.g. an instance URL that points to a server other than Daktela
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([new GuzzleResponse(200, [], '{}')]));

        $result = $communicator->healthCheck();

        $this->assertFalse($result['healthy']);
        $this->assertSame(200, $result['status_code']);
        $this->assertSame(
            'The API returned an unexpected response without user information; check the instance URL.',
            $result['error']
        );
    }

    private function okResponse(): GuzzleResponse
    {
        return new GuzzleResponse(200, [], json_encode(['error' => [], 'result' => ['data' => [], 'total' => 0]]));
    }

    /**
     * Builds a whoim response in the shape the Daktela API returns it.
     * @param array|null $user identified user, or null when the access token was not accepted
     */
    private function whoimResponse(?array $user): GuzzleResponse
    {
        return new GuzzleResponse(200, [], json_encode([
            'error' => [],
            'result' => [
                'version' => [
                    'current' => '2026.1',
                    'extApps' => ['mobileAndroid' => null, 'mobileIos' => null, 'webExtension' => null],
                ],
                'user' => $user,
            ],
            '_time' => '2026-10-14 10:00:00',
        ]));
    }

    public function testPostIsNotRetriedAfterTimeout(): void
    {
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([GuzzleExceptions::timeoutBeforeResponse(new Request('POST', '/api/v6/users.json')), $this->okResponse()], $history));
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0));

        try {
            $communicator->sendRequest('POST', 'Users', [], ['name' => 'x']);
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertCount(1, $history);
        }
    }

    public function testPostIsRetriedWhenConnectionWasRefused(): void
    {
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([
            fn (RequestInterface $request) => GuzzleExceptions::connectFailure($request),
            $this->okResponse(),
        ], $history));
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0));

        $this->assertTrue($communicator->sendRequest('POST', 'Users', [], ['name' => 'x'])->isSuccess());
        $this->assertCount(2, $history);
    }

    /**
     * @dataProvider redirectStatusProvider
     */
    public function testPostIsNotRepeatedWhenRedirectTargetCannotBeReached(int $status): void
    {
        // The server received the POST and answered with a redirect, e.g. 303 See Other after creating the record;
        // a connection failure on the redirect hop does not mean that the POST was not sent
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse($status, ['Location' => 'https://other.example.org/created/1']),
            fn (RequestInterface $hop) => GuzzleExceptions::connectFailure($hop),
            $this->okResponse(),
        ], $history));
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0));

        try {
            $communicator->sendRequest('POST', 'Users', [], ['name' => 'x']);
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringContainsString('Failed to connect', $ex->getMessage());
        }
        $this->assertCount(2, $history, 'The POST must not be sent again');
        $this->assertSame('https://other.example.org/created/1', (string)$history[1]['request']->getUri());
    }

    public static function redirectStatusProvider(): array
    {
        return [
            '301 Moved Permanently' => [301],
            '302 Found' => [302],
            '303 See Other' => [303],
            '307 Temporary Redirect' => [307],
            '308 Permanent Redirect' => [308],
        ];
    }

    /**
     * @dataProvider redirectHopProvider
     */
    public function testPostIsRepeatedOnlyWhenTheHopThatFailedIsTheSentRequest(
        string $instance,
        int $status,
        string $location,
        bool $repeated
    ): void {
        $history = [];
        $communicator = new ApiCommunicator($instance, 'token');
        // With a base URI, a relative Location resolves to an absolute URL that the client follows
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse($status, ['Location' => $location]),
            fn (RequestInterface $hop) => GuzzleExceptions::connectFailure($hop),
            $this->okResponse(),
        ], $history, $instance));
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0));

        try {
            $this->assertTrue($communicator->sendRequest('POST', 'Users', [], ['name' => 'x'])->isSuccess());
            $this->assertTrue($repeated, 'The POST must not be sent again');
        } catch (RequestException $ex) {
            $this->assertFalse($repeated, 'The POST should have been sent again');
        }
        $this->assertCount($repeated ? 3 : 2, $history);
        $expectedHop = str_starts_with($location, '/') ? $instance . $location : $location;
        $this->assertSame($expectedHop, (string)$history[1]['request']->getUri());
    }

    public static function redirectHopProvider(): array
    {
        return [
            // 303 See Other to the same URL: the hop differs from the POST only in its method
            'same URL, GET' => ['https://example.com', 303, '/api/v6/users.json', false],
            'same path, other query' => ['https://example.com', 307, '/api/v6/users.json?page=2', false],
            'other path on the instance' => ['https://example.com', 307, '/api/v6/users/created.json', false],
            'same path and query on another host' => ['https://example.com', 307, 'https://other.example.org/api/v6/users.json', false],
            'same path and query on another port' => ['https://example.com', 308, 'https://example.com:8443/api/v6/users.json', false],
            // Accepted: an instance redirecting a request to its own HTTPS URL did not process it
            'same path and query, upgraded to HTTPS on the instance' => ['http://example.com', 308, 'https://example.com/api/v6/users.json', true],
        ];
    }

    public function testPostIsRetriedWhenItsOwnConnectionFailsWithDotSegmentsInEndpoint(): void
    {
        // The client removes dot segments when it resolves the URI against its base URI
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([
            fn (RequestInterface $request) => GuzzleExceptions::connectFailure($request),
            $this->okResponse(),
        ], $history, 'https://example.com'));
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0));

        $this->assertTrue($communicator->sendRequest('POST', 'tickets/./comments', [], ['text' => 'x'])->isSuccess());
        $this->assertCount(2, $history);
        $this->assertSame('/api/v6/tickets/comments.json', $history[0]['request']->getUri()->getPath());
    }

    public function testConnectionFailureWithInstanceUrlGuzzleCannotParseIsWrapped(): void
    {
        // A custom client has its own base URI; the port of the instance URL is out of range
        $history = [];
        $communicator = new ApiCommunicator('https://example.com:99999', 'token');
        $communicator->setHttpClient($this->createMockClient([
            fn (RequestInterface $request) => GuzzleExceptions::connectFailure($request),
            $this->okResponse(),
        ], $history, 'https://example.com'));
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0));

        try {
            $communicator->sendRequest('POST', 'Users', [], ['name' => 'x']);
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringContainsString('Failed to connect', $ex->getMessage());
        }
        $this->assertCount(1, $history);
    }

    public function testPostIsNotRetriedWhenCustomClientSendsItElsewhere(): void
    {
        // The connector cannot tell a request sent to another host, e.g. through a gateway, from a redirect hop
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([
            fn (RequestInterface $request) => GuzzleExceptions::connectFailure($request),
            $this->okResponse(),
        ], $history, 'https://gateway.example.org'));
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0));

        try {
            $communicator->sendRequest('POST', 'Users', [], ['name' => 'x']);
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertCount(1, $history);
        }
    }

    public function testGetIsRetriedWhenRedirectTargetCannotBeReached(): void
    {
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(302, ['Location' => 'https://other.example.org/users.json']),
            fn (RequestInterface $hop) => GuzzleExceptions::connectFailure($hop),
            $this->okResponse(),
        ], $history));
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0));

        $this->assertTrue($communicator->sendRequest('GET', 'Users')->isSuccess());
        $this->assertCount(3, $history);
    }

    /**
     * @dataProvider authenticationMethods
     */
    public function testPostIsRetriedWhenItsOwnConnectionFails(int $method, string $expectedUri): void
    {
        // The exception carries the request as the HTTP client sent it, resolved against its base URI
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'to+k/en=');
        $communicator->setAuthenticationMethod($method);
        $communicator->setHttpClient($this->createMockClient([
            fn (RequestInterface $request) => GuzzleExceptions::connectFailure($request),
            $this->okResponse(),
        ], $history, 'https://example.com'));
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0));

        $this->assertTrue($communicator->sendRequest('POST', 'Users', ['a' => 'b c'], ['name' => 'x'])->isSuccess());
        $this->assertCount(2, $history);
        $this->assertSame($expectedUri, (string)$history[0]['request']->getUri());
    }

    public static function authenticationMethods(): array
    {
        return [
            'header' => [ApiCommunicator::AUTHENTICATION_METHOD_HEADER, 'https://example.com/api/v6/users.json?a=b+c'],
            'query' => [ApiCommunicator::AUTHENTICATION_METHOD_QUERY, 'https://example.com/api/v6/users.json?a=b+c&accessToken=to%2Bk%2Fen%3D'],
        ];
    }

    public function testPostIsNotRetriedOnServerError(): void
    {
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([new GuzzleResponse(503), $this->okResponse()], $history));
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0));

        $this->expectException(RequestException::class);
        try {
            $communicator->sendRequest('POST', 'Users', [], ['name' => 'x']);
        } finally {
            $this->assertCount(1, $history);
        }
    }

    public function testPostRetryCanBeEnabledExplicitly(): void
    {
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([GuzzleExceptions::timeoutBeforeResponse(new Request('POST', '/api/v6/users.json')), $this->okResponse()], $history));
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0, retryNonIdempotentRequests: true));

        $this->assertTrue($communicator->sendRequest('POST', 'Users', [], ['name' => 'x'])->isSuccess());
        $this->assertCount(2, $history);
    }

    public function testPutIsStillRetriedOnServerError(): void
    {
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([new GuzzleResponse(503), $this->okResponse()], $history));
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0));

        $this->assertTrue($communicator->sendRequest('PUT', 'Users/x', [], ['name' => 'x'])->isSuccess());
        $this->assertCount(2, $history);
    }

    public function testAccessTokenIsRedactedFromExceptionsAndLogs(): void
    {
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string $message, array $context) use (&$logged): void {
            $logged[] = $message . json_encode($context);
        });
        $communicator = new ApiCommunicator('https://example.com', 'SECRET/TOKEN+1');
        $communicator->setAuthenticationMethod(ApiCommunicator::AUTHENTICATION_METHOD_QUERY);
        $communicator->setLogger($logger);
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(400, [], json_encode(['error' => ['bad'], 'result' => null])),
        ]));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringNotContainsString('SECRET', $ex->getMessage());
            // Guzzle 7 and early Guzzle 8 versions put the URL with its query, the token redacted, in the message;
            // later Guzzle 8 versions leave the query out
            $this->assertMatchesRegularExpression('#`GET /api/v6/users\.json(\?accessToken=\*\*\*)?`#', $ex->getMessage());
        }
        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString('SECRET', $line);
        }
    }

    public function testHttpErrorExposesStatusBodyAndApiErrors(): void
    {
        $body = json_encode(['error' => ['number' => 'required'], 'result' => null]);
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([new GuzzleResponse(400, [], $body)]));

        try {
            $communicator->sendRequest('POST', 'Users', [], []);
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame(400, $ex->getHttpStatus());
            $this->assertSame($body, $ex->getResponseBody());
            $this->assertSame(['number' => 'required'], $ex->getApiErrors());
        }
    }

    public function testHttpNotFoundThrowsNotFoundException(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(404, [], json_encode(['error' => ['Not found'], 'result' => null])),
        ]));

        try {
            $communicator->sendRequest('GET', 'Users/missing');
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $ex) {
            $this->assertSame(404, $ex->getCode());
            $this->assertSame(['Not found'], $ex->getApiErrors());
        }
    }

    public function testErrorsAreKeptWhenResultIsNull(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(200, [], json_encode(['error' => ['Access denied'], 'result' => null])),
        ]));

        $response = $communicator->sendRequest('GET', 'Users/x');

        $this->assertSame(['Access denied'], $response->getErrors());
    }

    public function testObjectShapedErrorsAreNormalizedToArray(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(200, [], json_encode(['error' => ['title' => 'required'], 'result' => ['id' => 1]])),
            new GuzzleResponse(200, [], json_encode(['error' => 'Plain message', 'result' => ['id' => 1]])),
        ]));

        $this->assertSame(['title' => 'required'], $communicator->sendRequest('GET', 'Users/x')->getErrors());
        $this->assertSame(['Plain message'], $communicator->sendRequest('GET', 'Users/x')->getErrors());
    }

    public function testBaseUrlPathIsPreserved(): void
    {
        $history = [];
        $communicator = new ApiCommunicator('https://example.com/daktela/', 'token');
        $communicator->setHttpClient($this->createMockClient([$this->okResponse()], $history));

        $communicator->sendRequest('GET', 'Users');

        $this->assertSame('/daktela/api/v6/users.json', $history[0]['request']->getUri()->getPath());
    }

    public function testErrorListEntriesKeepTheirDecodedShape(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(200, [], json_encode(['error' => [['message' => 'x']], 'result' => ['id' => 1]])),
        ]));

        $this->assertSame('x', $communicator->sendRequest('GET', 'Users/x')->getFirstError()->message);
    }

    public function testQueryAuthenticationDoesNotChainTokenBearingException(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setAuthenticationMethod(ApiCommunicator::AUTHENTICATION_METHOD_QUERY);
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(400, [], json_encode(['error' => ['echo SECRET'], 'result' => null])),
            new ConnectException('cURL error 7 for /?accessToken=SECRET', new Request('GET', '/?accessToken=SECRET')),
        ]));

        foreach ([1, 2] as $_) {
            try {
                $communicator->sendRequest('GET', 'Users');
                $this->fail('Expected RequestException');
            } catch (RequestException $ex) {
                $this->assertNull($ex->getPrevious());
                $this->assertStringNotContainsString('SECRET', $ex->getMessage());
                $this->assertStringNotContainsString('SECRET', (string)$ex->getResponseBody());
                $this->assertStringNotContainsString('SECRET', json_encode($ex->getApiErrors()));
            }
        }
    }

    public function testHeaderAuthenticationKeepsPreviousException(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([new GuzzleResponse(400, [], '{}')]));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertInstanceOf(GuzzleRequestException::class, $ex->getPrevious());
        }
    }

    /**
     * @dataProvider headerAuthenticationMethods
     */
    public function testHeaderAuthenticationChainsPreviousExceptionWithoutToken(int $method): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setAuthenticationMethod($method);
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(401, [], json_encode(['error' => ['Token SECRET is not valid']])),
        ]));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $previous = $ex->getPrevious();
            $this->assertInstanceOf(ClientException::class, $previous);
            $this->assertSame(401, $previous->getResponse()->getStatusCode());
            $this->assertStringNotContainsString('SECRET', $previous->getMessage());
            $this->assertStringNotContainsString('SECRET', (string)$previous->getResponse()->getBody());
            $this->assertFalse($previous->getRequest()->hasHeader('X-AUTH-TOKEN'));
            $this->assertFalse($previous->getRequest()->hasHeader('Authorization'));
        }
    }

    public static function headerAuthenticationMethods(): array
    {
        return [
            'X-AUTH-TOKEN' => [ApiCommunicator::AUTHENTICATION_METHOD_HEADER],
            'Bearer' => [ApiCommunicator::AUTHENTICATION_METHOD_BEARER],
        ];
    }

    public function testConnectionErrorChainsPreviousExceptionWithoutToken(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($this->createMockClient([
            new ConnectException(
                'Connection refused',
                new Request('GET', '/', ['X-AUTH-TOKEN' => 'SECRET']),
                new \RuntimeException('Stream failed for SECRET')
            ),
        ]));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $previous = $ex->getPrevious();
            $this->assertInstanceOf(ConnectException::class, $previous);
            $this->assertSame('Connection refused', $previous->getMessage());
            $this->assertFalse($previous->getRequest()->hasHeader('X-AUTH-TOKEN'));
            // The client's own previous exception, and its trace, may carry the token
            $this->assertNull($previous->getPrevious());
        }
    }

    /**
     * @dataProvider headerAuthenticationMethods
     */
    public function testTokenWithLineBreakIsNotLeakedByInvalidHeader(int $method): void
    {
        // e.g. a token read from a file without trimming the trailing newline
        $communicator = new ApiCommunicator('https://example.com', "SECRET\n");
        $communicator->setAuthenticationMethod($method);
        $communicator->setHttpClient($this->createMockClient([]));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringNotContainsString('SECRET', $ex->getMessage());
            $this->assertNull($ex->getPrevious());
        }
        $this->assertFalse($communicator->ping());
        $this->assertStringNotContainsString('SECRET', $communicator->healthCheck()['error']);
    }

    public function testHttpErrorMessageDoesNotContainPartOfEchoedToken(): void
    {
        $token = str_repeat('a1b2c3d4e5', 4);
        // The token starts inside the 120-byte body summary of the message and ends after it
        $body = json_encode(['error' => [str_repeat('x', 90) . ' ' . $token]]);
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string $message, array $context) use (&$logged): void {
            $logged[] = (string)($context['error'] ?? '');
        });
        $communicator = new ApiCommunicator('https://example.com', $token);
        $communicator->setLogger($logger);
        $communicator->setHttpClient($this->createMockClient([new GuzzleResponse(401, [], $body)]));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringContainsString('401 Unauthorized', $ex->getMessage());
            $this->assertNotEmpty($logged);
            foreach (array_merge([$ex->getMessage()], $logged) as $text) {
                $this->assertStringNotContainsString(substr($token, 0, 10), $text);
            }
        }
    }

    public function testApiErrorsRedactTokenWrittenWithJsonEscapes(): void
    {
        $escapedToken = chr(92) . 'u0053ECRET';
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(400, [], '{"error":["Token ' . $escapedToken . ' is not valid"]}'),
        ]));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame(['Token *** is not valid'], $ex->getApiErrors());
        }
    }

    public function testResponseErrorsRedactToken(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient(new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new GuzzleResponse(400, [], json_encode(['error' => ['form' => ['name' => ['SECRET is taken']]], 'result' => null])),
            ])),
            'http_errors' => false,
        ]));

        $response = $communicator->sendRequest('GET', 'Users');

        $this->assertSame('{"form":{"name":["*** is taken"]}}', json_encode($response->getErrors()));
    }

    /**
     * @dataProvider crossOriginRedirects
     */
    public function testDefaultClientDropsXAuthTokenOnCrossOriginRedirect(string $location): void
    {
        $requests = [];
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $this->useDefaultClientWithMockHandler($communicator, [
            new GuzzleResponse(302, ['Location' => $location]),
            $this->okResponse(),
        ], $requests);

        $communicator->sendRequest('GET', 'Users');

        $this->assertCount(2, $requests);
        $this->assertSame('SECRET', $requests[0]->getHeaderLine('X-AUTH-TOKEN'));
        $this->assertFalse($requests[1]->hasHeader('X-AUTH-TOKEN'));
    }

    public static function crossOriginRedirects(): array
    {
        return [
            'another host' => ['https://other.example.org/api/v6/users.json'],
            'downgrade to http' => ['http://example.com/api/v6/users.json'],
            'another port' => ['https://example.com:8443/api/v6/users.json'],
        ];
    }

    /**
     * @dataProvider sameInstanceRedirects
     */
    public function testDefaultClientKeepsXAuthTokenOnSameInstanceRedirect(string $instance, string $location): void
    {
        $requests = [];
        $communicator = new ApiCommunicator($instance, 'SECRET');
        $this->useDefaultClientWithMockHandler($communicator, [
            new GuzzleResponse(302, ['Location' => $location]),
            $this->okResponse(),
        ], $requests);

        $communicator->sendRequest('GET', 'Users');

        $this->assertCount(2, $requests);
        $this->assertSame('SECRET', $requests[1]->getHeaderLine('X-AUTH-TOKEN'));
    }

    public static function sameInstanceRedirects(): array
    {
        return [
            'same host' => ['https://example.com', 'https://example.com/api/v6/users.json?moved=1'],
            'explicit default port' => ['https://example.com', 'https://example.com:443/api/v6/users.json'],
            'upgrade to https' => ['http://example.com', 'https://example.com/api/v6/users.json'],
        ];
    }

    public function testDefaultClientKeepsXAuthTokenOffAfterRedirectLeftInstance(): void
    {
        $requests = [];
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $this->useDefaultClientWithMockHandler($communicator, [
            new GuzzleResponse(307, ['Location' => 'https://other.example.org/x']),
            new GuzzleResponse(308, ['Location' => 'https://example.com/api/v6/users/admin.json']),
            $this->okResponse(),
        ], $requests);

        $communicator->sendRequest('PUT', 'Users/alice', [], ['title' => 'Alice']);

        $this->assertCount(3, $requests);
        $this->assertSame('SECRET', $requests[0]->getHeaderLine('X-AUTH-TOKEN'));
        $this->assertFalse($requests[1]->hasHeader('X-AUTH-TOKEN'));
        $this->assertFalse($requests[2]->hasHeader('X-AUTH-TOKEN'));
    }

    /**
     * @dataProvider tokensWithLineBreakAndBlanks
     */
    public function testTokenWithLineBreakAndBlanksIsNotLeaked(int $method, string $token): void
    {
        $communicator = new ApiCommunicator('https://example.com', $token);
        $communicator->setAuthenticationMethod($method);
        $communicator->setHttpClient($this->createMockClient([]));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringNotContainsString('SECRET', $ex->getMessage());
        }
    }

    public static function tokensWithLineBreakAndBlanks(): array
    {
        $cases = [];
        foreach (['X-AUTH-TOKEN' => ApiCommunicator::AUTHENTICATION_METHOD_HEADER, 'Bearer' => ApiCommunicator::AUTHENTICATION_METHOD_BEARER] as $name => $method) {
            $cases["$name, trailing newline and space"] = [$method, "SECRET\n "];
            $cases["$name, leading space"] = [$method, " SECRET\n"];
            $cases["$name, trailing newline and tab"] = [$method, "SECRET\n\t"];
        }

        return $cases;
    }

    public function testUnreadableResponseBodyDoesNotBreakErrorHandling(): void
    {
        $body = Psr7Utils::streamFor('partial SECRET');
        $body->detach();
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('send')->willThrowException(GuzzleExceptions::brokenResponse(
            new Request('GET', '/', ['X-AUTH-TOKEN' => 'SECRET']),
            new GuzzleResponse(200, [], $body)
        ));
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($httpClient);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame('cURL error 56: Recv failure', $ex->getMessage());
            $this->assertSame('', (string)$ex->getPrevious()->getResponse()->getBody());
        }
    }

    public function testLargePartialResponseBodyIsNotCopiedIntoPreviousException(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('send')->willThrowException(GuzzleExceptions::brokenResponse(
            new Request('GET', '/', ['X-AUTH-TOKEN' => 'SECRET']),
            new GuzzleResponse(200, [], str_repeat('x', 2 * 1024 * 1024))
        ));
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($httpClient);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame(0, $ex->getPrevious()->getResponse()->getBody()->getSize());
        }
    }

    public function testPreviousExceptionRedactsTokenInHandlerContext(): void
    {
        // Guzzle 8 exceptions have no handler context
        GuzzleExceptions::requireGuzzle7($this);
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('send')->willThrowException(new ConnectException(
            'Connection reset',
            new Request('GET', '/', ['X-AUTH-TOKEN' => 'SECRET']),
            null,
            ['request_header' => "GET / HTTP/1.1\r\nX-AUTH-TOKEN: SECRET\r\n"]
        ));
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($httpClient);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringNotContainsString('SECRET', $ex->getPrevious()->getHandlerContext()['request_header']);
        }
    }

    public function testPreviousExceptionRedactsTokenInResponseHeaders(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(401, ['X-Echo' => 'SECRET'], '{}'),
        ]));
        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame('***', $ex->getPrevious()->getResponse()->getHeaderLine('X-Echo'));
        }
    }

    public function testSelfReferencingHandlerContextDoesNotBreakErrorHandling(): void
    {
        GuzzleExceptions::requireGuzzle7($this);
        $object = new \stdClass();
        $object->self = $object;
        $array = ['level' => 'SECRET'];
        $array['self'] = &$array;
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('send')->willThrowException(new ConnectException(
            'Connection reset',
            new Request('GET', '/', ['X-AUTH-TOKEN' => 'SECRET']),
            null,
            ['object' => $object, 'array' => $array, 'errno' => 56, 'error' => 'reset for SECRET']
        ));
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($httpClient);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $context = $ex->getPrevious()->getHandlerContext();
            $this->assertArrayNotHasKey('object', $context);
            $this->assertSame(56, $context['errno']);
            $this->assertSame('reset for ***', $context['error']);
            $this->assertSame('***', $context['array']['level']);
        }
    }

    /**
     * @dataProvider headerAuthenticationMethods
     */
    public function testTokenWithSurroundingBlanksIsRedactedWhenEchoed(int $method): void
    {
        // Guzzle trims header values, so the server receives and may echo the token without the blanks
        $communicator = new ApiCommunicator('https://example.com', " SECRET\t");
        $communicator->setAuthenticationMethod($method);
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(401, [], json_encode(['error' => ['Token SECRET is not valid']])),
        ]));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringNotContainsString('SECRET', $ex->getMessage());
            $this->assertStringNotContainsString('SECRET', (string)$ex->getResponseBody());
            $this->assertSame(['Token *** is not valid'], $ex->getApiErrors());
        }
    }

    public function testPreviousExceptionKeepsClassAndMessageOfRedirectErrors(): void
    {
        $request = new Request('GET', '/', ['X-AUTH-TOKEN' => 'SECRET']);
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('send')->willThrowException(new TooManyRedirectsException(
            'Will not follow more than 5 redirects',
            $request,
            new GuzzleResponse(302, ['Location' => '/again'])
        ));
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($httpClient);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertInstanceOf(TooManyRedirectsException::class, $ex->getPrevious());
            $this->assertSame('Will not follow more than 5 redirects', $ex->getPrevious()->getMessage());
        }

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('send')->willThrowException(new BadResponseException(
            'Redirect URI, ftp://example.com, does not use one of the allowed redirect protocols: http, https',
            $request,
            new GuzzleResponse(302, ['Location' => 'ftp://example.com'])
        ));
        $communicator->setHttpClient($httpClient);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringStartsWith('Redirect URI, ftp://example.com', $ex->getMessage());
            $this->assertInstanceOf(BadResponseException::class, $ex->getPrevious());
            $this->assertStringStartsWith('Redirect URI, ftp://example.com', $ex->getPrevious()->getMessage());
        }
    }

    /**
     * Replaces the network handler of the communicator's default client, keeping its middleware.
     * @param RequestInterface[] $requests receives every request that reaches the network handler
     */
    private function useDefaultClientWithMockHandler(ApiCommunicator $communicator, array $responses, array &$requests): void
    {
        $method = new \ReflectionMethod($communicator, 'defaultClient');
        /** @var Client $client */
        $client = $method->invoke($communicator);
        $mock = new MockHandler($responses);
        $client->getConfig('handler')->setHandler(function (RequestInterface $request, array $options) use ($mock, &$requests) {
            $requests[] = $request;
            return $mock($request, $options);
        });
        // The redirect tests cover the X-AUTH-TOKEN header, which Guzzle does not remove itself
        $communicator->setAuthenticationMethod(ApiCommunicator::AUTHENTICATION_METHOD_HEADER);
        $communicator->setHttpClient($client);
    }

    public function testJsonEscapedTokenIsRedactedFromResponseBody(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'ab/cd');
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(400, [], '{"error":["bad token ab\\/cd"]}'),
        ]));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame(['bad token ***'], $ex->getApiErrors());
        }
    }

    public function testPostIsRetriedOnRateLimit(): void
    {
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(429, ['Retry-After' => '0']),
            $this->okResponse(),
        ], $history));
        $communicator->setRateLimitConfig(new RateLimitConfig());

        $this->assertTrue($communicator->sendRequest('POST', 'Users', [], ['name' => 'x'])->isSuccess());
        $this->assertCount(2, $history);
    }

    public function testPostRetryOnServerErrorCanBeEnabledExplicitly(): void
    {
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([new GuzzleResponse(503), $this->okResponse()], $history));
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0, retryNonIdempotentRequests: true));

        $this->assertTrue($communicator->sendRequest('POST', 'Users', [], ['name' => 'x'])->isSuccess());
        $this->assertCount(2, $history);
    }

    public function testBaseUrlEndingWithApiNamespaceIsNotDuplicated(): void
    {
        $history = [];
        $communicator = new ApiCommunicator('https://example.com/api/v6/', 'token');
        $communicator->setHttpClient($this->createMockClient([$this->okResponse()], $history));

        $communicator->sendRequest('GET', 'Users');

        $this->assertSame('/api/v6/users.json', $history[0]['request']->getUri()->getPath());
    }

    /**
     * @dataProvider postConnectErrorProvider
     */
    public function testPostRetryOnConnectErrorDependsOnWhetherRequestWasSent(?int $errno, int $expectedRequests): void
    {
        // Guzzle 7 reports timeouts as ConnectException as well, so the cURL error number decides
        GuzzleExceptions::requireGuzzle7($this);
        $history = [];
        // Built from the request the client sent, as Guzzle does
        $error = fn (RequestInterface $request) => new ConnectException('cURL error', $request, null, $errno === null ? [] : ['errno' => $errno]);
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([$error, $this->okResponse()], $history));
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0));

        try {
            $communicator->sendRequest('post', 'Users', [], ['name' => 'x']);
        } catch (RequestException $ex) {
            // Not retried
        }

        $this->assertCount($expectedRequests, $history);
    }

    public function postConnectErrorProvider(): array
    {
        return [
            'could not resolve host' => [6, 2],
            'could not connect' => [7, 2],
            'TLS handshake failed' => [35, 2],
            'timeout' => [28, 1],
            'receive failure' => [56, 1],
            'unknown' => [null, 1],
        ];
    }

    /**
     * @dataProvider postNetworkErrorProvider
     */
    public function testPostRetryOnNetworkErrorDependsOnWhetherRequestWasSent(string $class, int $expectedRequests): void
    {
        // Guzzle 8 throws ConnectException only when the connection could not be established
        GuzzleExceptions::requireGuzzle8($this);
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([
            fn (RequestInterface $request) => new $class('failure', $request),
            $this->okResponse(),
        ], $history));
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0));

        try {
            $communicator->sendRequest('POST', 'Users', [], ['name' => 'x']);
        } catch (RequestException $ex) {
            // Not retried
        }

        $this->assertCount($expectedRequests, $history);
    }

    public static function postNetworkErrorProvider(): array
    {
        return [
            'could not connect' => [ConnectException::class, 2],
            'connect timeout' => [\GuzzleHttp\Exception\ConnectTimeoutException::class, 2],
            'timeout before the response' => [\GuzzleHttp\Exception\NetworkTimeoutException::class, 1],
            'connection reset before the response' => [\GuzzleHttp\Exception\NetworkException::class, 1],
        ];
    }

    public function testGetIsRetriedAfterTimeoutBeforeResponse(): void
    {
        // Guzzle 8 reports the timeout as NetworkTimeoutException, which is not a ConnectException
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([
            GuzzleExceptions::timeoutBeforeResponse(new Request('GET', '/')),
            $this->okResponse(),
        ], $history));
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0));

        $this->assertTrue($communicator->sendRequest('GET', 'Users')->isSuccess());
        $this->assertCount(2, $history);
    }

    public function testGetIsRetriedAfterResponseTimeout(): void
    {
        // Guzzle 7 reported a timeout after the response headers as ConnectException as well
        GuzzleExceptions::requireGuzzle8($this);
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([
            new \GuzzleHttp\Exception\ResponseTimeoutException('cURL error 28', new Request('GET', '/'), new GuzzleResponse(200)),
            $this->okResponse(),
        ], $history));
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0));

        $this->assertTrue($communicator->sendRequest('GET', 'Users')->isSuccess());
        $this->assertCount(2, $history);
    }

    public function testPostIsNotRetriedWhenResponseBrokeOff(): void
    {
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([
            GuzzleExceptions::brokenResponse(new Request('POST', '/'), new GuzzleResponse(200)),
            $this->okResponse(),
        ], $history));
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0));

        try {
            $communicator->sendRequest('POST', 'Users', [], ['name' => 'x']);
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame('cURL error 56: Recv failure', $ex->getMessage());
        }
        $this->assertCount(1, $history);
    }

    public function testNetworkExceptionOfCustomClientIsRetriedAndNotChained(): void
    {
        $error = new class ('Proxy reset for SECRET', new Request('GET', '/')) extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
            private RequestInterface $request;

            public function __construct(string $message, RequestInterface $request)
            {
                parent::__construct($message);
                $this->request = $request;
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->exactly(4))
            ->method('send')
            ->will($this->onConsecutiveCalls(
                $this->throwException($error),
                $this->okResponse(),
                $this->throwException($error),
                $this->throwException($error)
            ));
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($httpClient);
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0));

        $this->assertTrue($communicator->sendRequest('GET', 'Users')->isSuccess());

        // Nothing proves that the request was not sent, so a POST is not repeated
        try {
            $communicator->sendRequest('POST', 'Users', [], ['name' => 'x']);
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame('Proxy reset for ***', $ex->getMessage());
        }

        $communicator->setRetryConfig(null);
        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame('Proxy reset for ***', $ex->getMessage());
            // Not a Guzzle exception: its own previous exceptions may carry the token
            $this->assertNull($ex->getPrevious());
        }
    }

    public function testNetworkErrorIsChainedAsCopyOfItsClassWithoutToken(): void
    {
        GuzzleExceptions::requireGuzzle8($this);
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('send')->willThrowException(new \GuzzleHttp\Exception\NetworkTimeoutException(
            'Timed out sending SECRET',
            new Request('GET', '/', ['X-AUTH-TOKEN' => 'SECRET', 'Authorization' => 'Bearer SECRET']),
            new \RuntimeException('Stream failed for SECRET')
        ));
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($httpClient);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $previous = $ex->getPrevious();
            $this->assertInstanceOf(\GuzzleHttp\Exception\NetworkTimeoutException::class, $previous);
            $this->assertSame('Timed out sending ***', $ex->getMessage());
            $this->assertSame('Timed out sending ***', $previous->getMessage());
            $this->assertFalse($previous->getRequest()->hasHeader('X-AUTH-TOKEN'));
            $this->assertFalse($previous->getRequest()->hasHeader('Authorization'));
            $this->assertNull($previous->getPrevious());
        }
    }

    public function testResponseTimeoutIsChainedAsCopyWithResponseWithoutToken(): void
    {
        GuzzleExceptions::requireGuzzle8($this);
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('send')->willThrowException(new \GuzzleHttp\Exception\ResponseTimeoutException(
            'Response body stalled',
            new Request('GET', '/', ['X-AUTH-TOKEN' => 'SECRET']),
            new GuzzleResponse(200, ['X-Echo' => 'SECRET'], '{"partial":"SECRET'),
            new \RuntimeException('Read failed for SECRET')
        ));
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($httpClient);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $previous = $ex->getPrevious();
            $this->assertInstanceOf(\GuzzleHttp\Exception\ResponseTimeoutException::class, $previous);
            $this->assertSame('{"partial":"***', (string)$previous->getResponse()->getBody());
            $this->assertSame('***', $previous->getResponse()->getHeaderLine('X-Echo'));
            $this->assertFalse($previous->getRequest()->hasHeader('X-AUTH-TOKEN'));
            $this->assertNull($previous->getPrevious());
        }
    }

    public function testCustomRequestExceptionWithOtherConstructorFallsBackToGuzzleRequestException(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('send')->willThrowException(new StrictRequestException(
            new Request('GET', '/', ['X-AUTH-TOKEN' => 'SECRET']),
            new \RuntimeException('Request body failed for SECRET')
        ));
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($httpClient);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame('Strict request failure', $ex->getMessage());
            $this->assertSame(GuzzleRequestException::class, get_class($ex->getPrevious()));
            $this->assertFalse($ex->getPrevious()->getRequest()->hasHeader('X-AUTH-TOKEN'));
            $this->assertNull($ex->getPrevious()->getPrevious());
        }
    }

    public function testBodySummaryWithLineBreakDoesNotKeepPartOfToken(): void
    {
        // Guzzle 8 escapes the line break of the summary in the message
        $token = str_repeat('a1b2c3d4e5', 4);
        $response = new GuzzleResponse(200, [], "line\n" . str_repeat('x', 90) . ' ' . $token);
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('send')->willThrowException(GuzzleRequestException::create(new Request('GET', '/'), $response));
        $communicator = new ApiCommunicator('https://example.com', $token);
        $communicator->setHttpClient($httpClient);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringContainsString('200 OK', $ex->getMessage());
            $this->assertStringContainsString(' ***', $ex->getMessage());
            $this->assertStringNotContainsString(substr($token, 0, 10), $ex->getMessage());
            $this->assertStringNotContainsString(substr($token, 0, 10), $ex->getPrevious()->getMessage());
        }
    }

    public function testExceptionWithResponseThatIsAlsoANetworkFailureKeepsRedactedResponse(): void
    {
        // The response is not dropped although the exception also implements NetworkExceptionInterface
        $token = str_repeat('a1b2c3d4e5', 4);
        $request = new Request('GET', '/', ['X-AUTH-TOKEN' => $token]);
        $response = new GuzzleResponse(200, [], json_encode(['error' => [str_repeat('x', 90) . ' ' . $token]]));
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('send')->willThrowException(new \Daktela\Tests\Support\NetworkBadResponseException(
            GuzzleRequestException::create($request, $response)->getMessage(),
            $request,
            $response
        ));
        $communicator = new ApiCommunicator('https://example.com', $token);
        $communicator->setHttpClient($httpClient);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringNotContainsString(substr($token, 0, 10), $ex->getMessage());
            $previous = $ex->getPrevious();
            $this->assertInstanceOf(\Daktela\Tests\Support\NetworkBadResponseException::class, $previous);
            $this->assertStringContainsString(' ***', (string)$previous->getResponse()->getBody());
        }
    }

    public function testBodySummaryInMessageOfCustomClientIsReplacedWhetherEscapedOrNot(): void
    {
        // A custom client may put the summary in its message unescaped, also on Guzzle 8
        $token = str_repeat('a1b2c3d4e5', 4);
        $request = new Request('GET', '/');
        $response = new GuzzleResponse(200, [], "line\n" . str_repeat('x', 90) . ' ' . $token);
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('send')->willThrowException(new BadResponseException(
            "Unexpected response:\n" . \GuzzleHttp\Psr7\Message::bodySummary($response),
            $request,
            $response
        ));
        $communicator = new ApiCommunicator('https://example.com', $token);
        $communicator->setHttpClient($httpClient);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringContainsString(' ***', $ex->getMessage());
            $this->assertStringNotContainsString(substr($token, 0, 10), $ex->getMessage());
        }
    }

    public function testConnectionErrorLogDoesNotKeepPartOfTokenFromBodySummary(): void
    {
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        foreach (['debug', 'info', 'warning', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(function (string $message, array $context = []) use (&$logged): void {
                $logged[] = $message . json_encode($context);
            });
        }
        $token = str_repeat('a1b2c3d4e5', 4);
        $response = new GuzzleResponse(200, [], str_repeat('x', 90) . ' ' . $token);
        $communicator = new ApiCommunicator('https://example.com', $token);
        $communicator->setLogger($logger);
        $communicator->setHttpClient($this->createMockClient([
            fn (RequestInterface $request) => GuzzleExceptions::brokenResponse(
                $request,
                $response,
                'Response broke off: ' . \GuzzleHttp\Psr7\Message::bodySummary($response)
            ),
        ]));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringNotContainsString(substr($token, 0, 10), $ex->getMessage());
        }
        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString(substr($token, 0, 10), $line);
        }
    }

    public function testMethodIsSentInUpperCase(): void
    {
        // Guzzle 8 sends the method as given
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([$this->okResponse()], $history));

        $communicator->sendRequest('post', 'Users', [], ['name' => 'x']);

        $this->assertSame('POST', $history[0]['request']->getMethod());
    }

    public function testUnparsableResponseIsNotChained(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($this->createMockClient([new GuzzleResponse(200, [], '<html>SECRET</html>')]));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame('json_decode error: Syntax error', $ex->getMessage());
            $this->assertSame(0, $ex->getCode());
            // The JSON exception's trace holds the raw body as an argument
            $this->assertNull($ex->getPrevious());
        }
    }

    /**
     * @dataProvider idempotentMethodProvider
     */
    public function testIdempotentMethodsAreRetriedOnServerError(string $method): void
    {
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([new GuzzleResponse(503), $this->okResponse()], $history));
        $communicator->setRetryConfig(new RetryConfig(baseDelayMs: 0));

        $communicator->sendRequest($method, 'Users/x');

        $this->assertCount(2, $history);
    }

    public function idempotentMethodProvider(): array
    {
        return [['GET'], ['PUT'], ['DELETE'], ['delete'], ['HEAD'], ['OPTIONS']];
    }

    public function testAccessTokenIsRedactedOnEveryRetryAndErrorPath(): void
    {
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        foreach (['debug', 'info', 'warning', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(function (string $message, array $context = []) use (&$logged): void {
                $logged[] = $message . json_encode($context);
            });
        }
        $connectError = fn(): ConnectException => GuzzleExceptions::connectFailure(
            new Request('GET', '/?accessToken=SECRET'),
            'cURL error 7: Failed to connect for https://example.com/?accessToken=SECRET'
        );
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setAuthenticationMethod(ApiCommunicator::AUTHENTICATION_METHOD_QUERY);
        $communicator->setLogger($logger);
        $communicator->setRetryConfig(new RetryConfig(maxRetries: 2, baseDelayMs: 0));
        $communicator->setHttpClient($this->createMockClient([
            $connectError(),
            $connectError(),
            $connectError(),
            GuzzleExceptions::transferException('Transfer failed for /?accessToken=SECRET', new Request('GET', '/?accessToken=SECRET')),
        ]));

        $messages = [];
        foreach ([1, 2] as $_) {
            try {
                $communicator->sendRequest('GET', 'Users');
                $this->fail('Expected RequestException');
            } catch (RequestException $ex) {
                $this->assertNull($ex->getPrevious());
                $messages[] = $ex->getMessage();
            }
        }

        $this->assertStringStartsWith('Max retries exceeded', $messages[0]);
        $this->assertNotEmpty($logged);
        foreach (array_merge($messages, $logged) as $line) {
            $this->assertStringNotContainsString('SECRET', $line);
        }
    }

    public function testHealthCheckErrorDoesNotContainAccessToken(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setAuthenticationMethod(ApiCommunicator::AUTHENTICATION_METHOD_QUERY);
        $communicator->setHttpClient($this->createMockClient([
            new ConnectException('cURL error 7 for /?accessToken=SECRET', new Request('GET', '/?accessToken=SECRET')),
        ]));

        $health = $communicator->healthCheck();

        $this->assertFalse($health['healthy']);
        $this->assertStringNotContainsString('SECRET', $health['error']);
    }

    public function testEncodedObjectNameSurvivesInRequestUri(): void
    {
        $history = [];
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([$this->okResponse()], $history));
        $client = new \Daktela\DaktelaV6\Client('https://example.com', 'token', $communicator);

        $client->execute(\Daktela\DaktelaV6\RequestFactory::buildReadSingleRequest('Users', 'a b?c#d'));

        $this->assertSame('/api/v6/users/a%20b%3Fc%23d.json', $history[0]['request']->getUri()->getPath());
    }

    public function testNonGuzzleExceptionFromClientIsWrappedAndRedacted(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('send')->willThrowException(
            new \InvalidArgumentException('Unable to parse URI: https://example.com/?accessToken=SECRET')
        );
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($httpClient);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame('Unable to parse URI: https://example.com/?accessToken=***', $ex->getMessage());
            $this->assertNull($ex->getPrevious());
        }
        $this->assertFalse($communicator->ping());
    }

    public function testNonGuzzleExceptionIsNotChained(): void
    {
        $original = new \RuntimeException('Proxy unavailable');
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('send')->willThrowException($original);
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($httpClient);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame('Proxy unavailable', $ex->getMessage());
            // Its own previous exceptions may carry the token, so nothing is chained
            $this->assertNull($ex->getPrevious());
        }
    }

    public function testBodySummaryInOtherGuzzleErrorDoesNotKeepPartOfToken(): void
    {
        $token = str_repeat('a1b2c3d4e5', 4);
        $response = new GuzzleResponse(200, [], json_encode(['error' => [str_repeat('x', 90) . ' ' . $token]]));
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('send')->willThrowException(GuzzleRequestException::create(new Request('GET', '/'), $response));
        $communicator = new ApiCommunicator('https://example.com', $token);
        $communicator->setHttpClient($httpClient);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringContainsString('200 OK', $ex->getMessage());
            $this->assertStringNotContainsString(substr($token, 0, 10), $ex->getMessage());
            $this->assertStringNotContainsString(substr($token, 0, 10), $ex->getPrevious()->getMessage());
        }
    }

    public function testCustomGuzzleExceptionSubclassIsKept(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('send')->willThrowException(new CustomBadResponseException(
            'Redirect refused',
            new Request('GET', '/', ['X-AUTH-TOKEN' => 'SECRET']),
            new GuzzleResponse(302),
            new \InvalidArgumentException('Invalid Location for SECRET')
        ));
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($httpClient);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertInstanceOf(CustomBadResponseException::class, $ex->getPrevious());
            $this->assertSame('Redirect refused', $ex->getPrevious()->getMessage());
            $this->assertNull($ex->getPrevious()->getPrevious());
        }
    }

    public function testCustomGuzzleExceptionWithOtherConstructorFallsBackToNearestGuzzleClass(): void
    {
        // Guzzle 8 does not allow a subclass of ClientException to override the constructor
        GuzzleExceptions::requireGuzzle7($this);
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('send')->willThrowException(new Guzzle7StrictClientException(
            new Request('GET', '/', ['X-AUTH-TOKEN' => 'SECRET']),
            new GuzzleResponse(401, [], '{}')
        ));
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($httpClient);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame(ClientException::class, get_class($ex->getPrevious()));
            $this->assertSame(401, $ex->getHttpStatus());
        }
    }

    public function testHttpErrorWithUnreadableBodyIsReportedAsRequestException(): void
    {
        $body = Psr7Utils::streamFor('error');
        $body->detach();
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($this->createMockClient([new GuzzleResponse(500, [], $body)]));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame(500, $ex->getHttpStatus());
            $this->assertSame('', $ex->getResponseBody());
        }
    }

    public function testApiErrorKeysAreRedacted(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'SECRET');
        $communicator->setHttpClient($this->createMockClient([
            new GuzzleResponse(400, [], json_encode(['error' => ['SECRET' => 'is not valid']])),
        ]));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame(['***' => 'is not valid'], $ex->getApiErrors());
        }

        $communicator->setHttpClient(new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new GuzzleResponse(400, [], json_encode(['error' => ['form' => ['SECRET' => ['taken']]]])),
            ])),
            'http_errors' => false,
        ]));
        $this->assertSame('{"form":{"***":["taken"]}}', json_encode($communicator->sendRequest('GET', 'Users')->getErrors()));
    }

    public function testRetryAfterHeaderIsUsedWithoutRateLimitConfig(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($this->createMockClient([new GuzzleResponse(429, ['Retry-After' => '7'])]));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $ex) {
            $this->assertSame(7, $ex->getRetryAfterSeconds());
        }
    }
}

class CustomBadResponseException extends BadResponseException
{
}
