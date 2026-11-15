<?php

declare(strict_types=1);

namespace Daktela\Tests\Unit\Http;

use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\Http\ApiCommunicator;
use Daktela\DaktelaV6\Http\RetryConfig;
use Daktela\Tests\Support\ExposesTraces;
use Daktela\Tests\Support\GuzzleExceptions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The access token must not appear in the trace arguments of any exception the connector throws or chains:
 * PHP records the current values of the parameters of every frame, and loggers, error trackers, and debug
 * output may print them.
 */
class ApiCommunicatorTraceTest extends TestCase
{
    use ExposesTraces;

    private const TOKEN = 'SECRETa1b2c3d4e5';

    /**
     * @dataProvider failures
     */
    public function testAccessTokenIsNotInTracesOfTheExceptionChain(int $method, array $responses, bool $retry): void
    {
        $communicator = new ApiCommunicator('https://example.com', self::TOKEN);
        $communicator->setAuthenticationMethod($method);
        $communicator->setHttpClient(new Client([
            'base_uri' => 'https://example.com',
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]));
        if ($retry) {
            $communicator->setRetryConfig(new RetryConfig(maxRetries: 1, baseDelayMs: 0));
        }

        try {
            $communicator->sendRequest('GET', 'Users', ['q' => 'x']);
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringNotContainsString('SECRET', self::exposed($ex));
        }
    }

    public static function failures(): array
    {
        $failures = [
            'connection failure' => [[fn (RequestInterface $request) => GuzzleExceptions::connectFailure($request)], false],
            'connection failure after the retries' => [[
                fn (RequestInterface $request) => GuzzleExceptions::connectFailure($request),
                fn (RequestInterface $request) => GuzzleExceptions::connectFailure($request),
            ], true],
            'HTTP error echoing the token' => [[
                new GuzzleResponse(401, ['X-Echo' => self::TOKEN], '{"error":["Token ' . self::TOKEN . ' is not valid"]}'),
            ], false],
            'not found' => [[new GuzzleResponse(404, ['X-Echo' => self::TOKEN], self::TOKEN . ' not found')], false],
            'response that broke off' => [[
                fn (RequestInterface $request) => GuzzleExceptions::brokenResponse(
                    $request,
                    new GuzzleResponse(200, ['X-Echo' => self::TOKEN], '{"partial":"' . self::TOKEN)
                ),
            ], false],
            'rate limit' => [[new GuzzleResponse(429, ['Retry-After' => '7', 'X-Echo' => self::TOKEN])], false],
            'unparsable response' => [[new GuzzleResponse(200, ['X-Echo' => self::TOKEN], '<html>' . self::TOKEN . '</html>')], false],
            'other request failure' => [[fn (RequestInterface $request) => new GuzzleRequestException('Failed', $request)], false],
        ];

        $cases = [];
        $methods = [
            'header' => ApiCommunicator::AUTHENTICATION_METHOD_HEADER,
            'bearer' => ApiCommunicator::AUTHENTICATION_METHOD_BEARER,
            'query' => ApiCommunicator::AUTHENTICATION_METHOD_QUERY,
        ];
        foreach ($methods as $methodName => $method) {
            foreach ($failures as $failureName => [$responses, $retry]) {
                $cases["$methodName, $failureName"] = [$method, $responses, $retry];
            }
        }

        return $cases;
    }

    public function testAccessTokenIsNotInTraceOfInvalidRequestWithQueryAuthentication(): void
    {
        // The request is rejected before it is sent, after the token was added to its query
        $communicator = new ApiCommunicator('https://example.com', self::TOKEN);
        $communicator->setAuthenticationMethod(ApiCommunicator::AUTHENTICATION_METHOD_QUERY);
        $communicator->setUserAgentSuffix("App\n1.0");

        try {
            $communicator->sendRequest('GET', 'Users', ['q' => 'x']);
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringNotContainsString('SECRET', self::exposed($ex));
        }
    }
}
