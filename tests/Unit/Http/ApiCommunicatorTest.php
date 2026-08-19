<?php

declare(strict_types=1);

namespace Daktela\Tests\Unit\Http;

use Daktela\DaktelaV6\Exception\RateLimitException;
use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\Http\ApiCommunicator;
use Daktela\DaktelaV6\Http\RateLimitConfig;
use Daktela\DaktelaV6\Http\RetryConfig;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApiCommunicatorTest extends TestCase
{
    private function getPrivateProperty(object $object, string $name): mixed
    {
        $property = new \ReflectionProperty($object, $name);
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        return $property->getValue($object);
    }

    private function createMockClient(array $responses, ?array &$history = null): Client
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        if ($history !== null) {
            $handlerStack->push(Middleware::history($history));
        }
        return new Client(['handler' => $handlerStack]);
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
        $method = new \ReflectionMethod($communicator, 'createDefaultClient');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        /** @var Client $client */
        $client = $method->invoke($communicator);

        $this->assertSame('https://example.com', (string)$client->getConfig('base_uri'));
        $this->assertSame(12.5, $client->getConfig('timeout'));
        $this->assertFalse($client->getConfig('verify'));
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
            $this->assertSame($guzzleException, $exception->getPrevious());
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
        $mockClient = $this->createMockClient([
            new GuzzleResponse(200, [], json_encode([
                'result' => ['data' => [], 'total' => 0],
            ])),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);

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
        $mockClient = $this->createMockClient([
            new GuzzleResponse(200, [], json_encode([
                'result' => ['data' => [], 'total' => 0],
            ])),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);

        $result = $communicator->healthCheck();

        $this->assertTrue($result['healthy']);
        $this->assertArrayHasKey('latency_ms', $result);
        $this->assertEquals(200, $result['status_code']);
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
    }
}
