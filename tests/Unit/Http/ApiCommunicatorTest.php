<?php

declare(strict_types=1);

namespace Daktela\Tests\Unit\Http;

use Daktela\DaktelaV6\Exception\RateLimitException;
use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\Http\ApiCommunicator;
use Daktela\DaktelaV6\Http\RateLimitConfig;
use Daktela\DaktelaV6\Http\RetryConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApiCommunicatorTest extends TestCase
{
    private function createMockClient(array $responses): Client
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
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

        // No direct way to verify, but should not throw
        $this->assertTrue(true);
    }

    public function testSetAuthenticationMethodHeader(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setAuthenticationMethod(ApiCommunicator::AUTHENTICATION_METHOD_HEADER);

        // No direct way to verify, but should not throw
        $this->assertTrue(true);
    }

    public function testSetAuthenticationMethodQuery(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setAuthenticationMethod(ApiCommunicator::AUTHENTICATION_METHOD_QUERY);

        // No direct way to verify, but should not throw
        $this->assertTrue(true);
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

    public function testSendRequestWithErrors(): void
    {
        $mockClient = $this->createMockClient([
            new GuzzleResponse(400, [], json_encode([
                'result' => ['data' => null],
                'error' => ['Invalid request'],
            ])),
        ]);

        $communicator = new ApiCommunicator('https://example.com', 'token');
        $communicator->setHttpClient($mockClient);

        $response = $communicator->sendRequest('GET', 'Users');

        $this->assertEquals(400, $response->getHttpStatus());
        $this->assertEquals(['Invalid request'], $response->getErrors());
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
