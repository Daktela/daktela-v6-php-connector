<?php

declare(strict_types=1);

namespace Daktela\Tests\Unit\Http;

use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\Http\ApiCommunicator;
use Daktela\DaktelaV6\Http\Internal\Credentials;
use Daktela\DaktelaV6\Http\Internal\Redactor;
use Daktela\DaktelaV6\Http\Internal\RequestBuilder;
use Daktela\Tests\Support\ExposesTraces;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Response shapes, instance URLs, the default HTTP client, and login errors that earlier releases handled wrongly.
 */
class ApiCommunicatorHardeningTest extends TestCase
{
    use ExposesTraces;

    /** @var array history of the requests sent through the mock client */
    private array $history = [];

    private function communicator(array $responses, string $baseUrl = 'https://example.com', string $token = 'token'): ApiCommunicator
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $communicator = new ApiCommunicator($baseUrl, $token);
        $communicator->setHttpClient(new Client(['handler' => $stack]));

        return $communicator;
    }

    private static function invoke(object $object, string $method): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);

        return $reflection->invoke($object);
    }

    public function testNullDataOfAListIsReturnedAsNull(): void
    {
        $communicator = $this->communicator([
            new GuzzleResponse(200, [], '{"error":["Page failed"],"result":{"data":null,"total":3}}'),
        ]);

        $response = $communicator->sendRequest('GET', 'Users');

        $this->assertNull($response->getData());
        $this->assertTrue($response->isEmpty());
        $this->assertSame(3, $response->getTotal());
        $this->assertSame(['Page failed'], $response->getErrors());
    }

    public function testRecordWithItsOwnNullDataFieldIsReturnedWhole(): void
    {
        $communicator = $this->communicator([
            new GuzzleResponse(200, [], '{"error":[],"result":{"name":"record_1","data":null}}'),
        ]);

        $data = $communicator->sendRequest('GET', 'Records/record_1')->getData();

        $this->assertIsObject($data);
        $this->assertSame('record_1', $data->name);
    }

    public function testScalarResultIsReturnedAsData(): void
    {
        $communicator = $this->communicator([new GuzzleResponse(200, [], '{"error":[],"result":true}')]);

        $response = $communicator->sendRequest('DELETE', 'Users/user_1');

        $this->assertTrue($response->getData());
        $this->assertSame(1, $response->getTotal());
    }

    public function testErrorThrownByCustomClientIsWrappedWithoutTheToken(): void
    {
        $token = 'secret-token-0123456789';
        $communicator = new ApiCommunicator('https://example.com', $token);
        $communicator->setHttpClient(new Client(['handler' => HandlerStack::create(
            static function (RequestInterface $request): void {
                throw new \TypeError('Middleware failed for ' . $request->getHeaderLine('Authorization'));
            }
        )]));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('A RequestException was expected');
        } catch (RequestException $ex) {
            $this->assertStringContainsString('Middleware failed for Bearer ***', $ex->getMessage());
            $this->assertNull($ex->getPrevious());
            $this->assertStringNotContainsString($token, self::exposed($ex));
        }
    }

    public function testInvalidInstanceUrlOfTheDefaultClientIsReportedAsRequestException(): void
    {
        $communicator = new ApiCommunicator('https://example.com:99999', 'token');

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('A RequestException was expected');
        } catch (RequestException $ex) {
            $this->assertSame('The instance URL is not a valid URL.', $ex->getMessage());
        }

        $health = $communicator->healthCheck();
        $this->assertFalse($health['healthy']);
        $this->assertSame('The instance URL is not a valid URL.', $health['error']);
        $this->assertFalse($communicator->ping());
    }

    public function testNormalizeUrlAcceptsSchemeInAnyCase(): void
    {
        $this->assertSame('HTTPS://example.com', ApiCommunicator::normalizeUrl('HTTPS://example.com/'));
        $this->assertSame('Http://example.com', ApiCommunicator::normalizeUrl('Http://example.com'));
        $this->assertSame('https://example.com', ApiCommunicator::normalizeUrl('example.com/'));
    }

    public function testQueryIsBuiltWithAmpersandWhateverTheIniSetting(): void
    {
        $communicator = $this->communicator([new GuzzleResponse(200, [], '{"result":{"data":[],"total":0}}')]);
        $separator = (string)ini_get('arg_separator.output');
        ini_set('arg_separator.output', '&amp;');
        try {
            $communicator->sendRequest('GET', 'Users', ['skip' => 0, 'take' => 10]);
        } finally {
            ini_set('arg_separator.output', $separator);
        }

        $this->assertSame('skip=0&take=10', $this->history[0]['request']->getUri()->getQuery());
    }

    /**
     * @dataProvider authenticationMethods
     */
    public function testInstancePathStartingWithTwoSlashesDoesNotNameAnotherHost(int $method): void
    {
        $communicator = $this->communicator(
            [new GuzzleResponse(200, [], '{"result":{"data":[],"total":0}}')],
            'https://acme.example.com//evil.example'
        );
        $communicator->setAuthenticationMethod($method);

        $communicator->sendRequest('GET', 'Users');

        $uri = $this->history[0]['request']->getUri();
        $this->assertSame('', $uri->getHost());
        $this->assertSame('/evil.example/api/v6/users.json', $uri->getPath());
    }

    public function authenticationMethods(): array
    {
        return [
            'header' => [ApiCommunicator::AUTHENTICATION_METHOD_HEADER],
            'bearer' => [ApiCommunicator::AUTHENTICATION_METHOD_BEARER],
            'query' => [ApiCommunicator::AUTHENTICATION_METHOD_QUERY],
        ];
    }

    /**
     * @dataProvider instanceUrlsWithOnlySlashesBeforeTheApiNamespace
     */
    public function testSlashesBeforeTheApiNamespaceDoNotNameAnotherHost(string $baseUrl): void
    {
        $communicator = $this->communicator([new GuzzleResponse(200, [], '{"result":{"data":[],"total":0}}')], $baseUrl);
        $communicator->setAuthenticationMethod(ApiCommunicator::AUTHENTICATION_METHOD_BEARER);

        $communicator->sendRequest('GET', 'Users');

        $uri = $this->history[0]['request']->getUri();
        $this->assertSame('', $uri->getHost());
        $this->assertSame('/api/v6/users.json', $uri->getPath());
    }

    public function instanceUrlsWithOnlySlashesBeforeTheApiNamespace(): array
    {
        return [
            'two slashes' => ['https://acme.example.com//api/v6'],
            'trailing slash' => ['https://acme.example.com//api/v6/'],
            'three slashes' => ['https://acme.example.com///api/v6'],
            'upper case' => ['https://acme.example.com//API/V6'],
            'no scheme' => ['acme.example.com//api/v6'],
            'plain' => ['https://acme.example.com/api/v6'],
            'only slashes' => ['https://acme.example.com//'],
        ];
    }

    public function testNonJsonBodyOfARedirectedLoginIsLeftOut(): void
    {
        $password = 'P&ss<word>';
        $communicator = $this->communicator(
            [new GuzzleResponse(302, ['Location' => '/login'], '<p>Wrong password ' . htmlspecialchars($password) . '</p>')],
            'https://example.com',
            ''
        );

        try {
            $communicator->login('api', $password);
            $this->fail('A RequestException was expected');
        } catch (RequestException $ex) {
            $this->assertSame('', $ex->getResponseBody());
            $this->assertStringNotContainsString(htmlspecialchars($password), self::exposed($ex));
        }
    }

    public function testLoginPayloadStaysOnTheInstanceWhenThePathStartsWithTwoSlashes(): void
    {
        $communicator = $this->communicator(
            [new GuzzleResponse(401, [], '{"error":["Invalid credentials"]}')],
            'https://acme.example.com//evil.example',
            ''
        );

        try {
            $communicator->login('api', 'password');
            $this->fail('A RequestException was expected');
        } catch (RequestException $ex) {
            $uri = $this->history[0]['request']->getUri();
            $this->assertSame('', $uri->getHost());
            $this->assertSame('/evil.example/api/v6/login.json', $uri->getPath());
        }
    }

    public function testDefaultClientIsReusedUntilATransportSettingChanges(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');

        /** @var Client $first */
        $first = self::invoke($communicator, 'defaultClient');
        $this->assertSame($first, self::invoke($communicator, 'defaultClient'));

        $communicator->setRequestTimeout(7.5);
        /** @var Client $second */
        $second = self::invoke($communicator, 'defaultClient');
        $this->assertNotSame($first, $second);
        $this->assertSame(7.5, $second->getConfig('timeout'));

        $communicator->setVerifySsl(false);
        /** @var Client $third */
        $third = self::invoke($communicator, 'defaultClient');
        $this->assertNotSame($second, $third);
        $this->assertFalse($third->getConfig('verify'));
    }

    public function testRequestsShareTheDefaultClient(): void
    {
        $logger = new class extends AbstractLogger {
            public array $messages = [];

            public function log($level, $message, array $context = []): void
            {
                $this->messages[] = (string)$message;
            }
        };
        // Nothing listens on port 1, so both requests fail to connect
        $communicator = new ApiCommunicator('http://127.0.0.1:1', 'token');
        $communicator->setRequestTimeout(2.0);
        $communicator->setLogger($logger);

        for ($i = 0; $i < 2; $i++) {
            try {
                $communicator->sendRequest('GET', 'Users');
                $this->fail('A RequestException was expected');
            } catch (RequestException $ex) {
                $this->assertNull($ex->getHttpStatus());
            }
        }

        // Logged when the default client is created
        $created = array_keys($logger->messages, 'The connection to the Daktela API is not secure', true);
        $this->assertCount(1, $created);
        $this->assertCount(2, array_keys($logger->messages, 'Connection error', true));
    }

    public function testClientOfAnotherProcessIsNotReused(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $inherited = self::invoke($communicator, 'defaultClient');

        // As after a fork: the cached client was created by a process with another id
        $property = new \ReflectionProperty(ApiCommunicator::class, 'defaultClients');
        $property->getValue()[$communicator] = [-1, $inherited];

        $own = self::invoke($communicator, 'defaultClient');
        $this->assertNotSame($inherited, $own);
        $this->assertSame($own, self::invoke($communicator, 'defaultClient'));
    }

    public function testOnlyLoginAndRefreshRequestsGetTheCredentials(): void
    {
        $credentials = new Credentials('token');
        $builder = new RequestBuilder(
            'https://example.com',
            $credentials,
            new Redactor($credentials),
            ApiCommunicator::AUTHENTICATION_METHOD_BEARER,
            null
        );

        $this->expectException(\LogicException::class);
        $builder->build('POST', 'whoim', [], null, false);
    }

    public function testLoginRequestIsLoggedAsHavingABody(): void
    {
        $logger = new class extends AbstractLogger {
            public array $entries = [];

            public function log($level, $message, array $context = []): void
            {
                $this->entries[] = [(string)$message, $context];
            }
        };
        $communicator = $this->communicator([
            new GuzzleResponse(200, [], '{"error":[],"result":{"accessToken":"a","refreshToken":"r"}}'),
            new GuzzleResponse(200, [], '{"error":[],"result":[]}'),
        ]);
        $communicator->setLogger($logger);
        $communicator->login('api', 'password');
        $communicator->sendRequest('GET', 'Users');

        $sent = array_values(array_filter($logger->entries, static fn (array $entry): bool => $entry[0] === 'Sending API request'));
        $this->assertCount(2, $sent);
        $this->assertTrue($sent[0][1]['has_body']);
        $this->assertFalse($sent[1][1]['has_body']);
    }

    public function testCloneBuildsItsOwnDefaultClientAndTheObjectStaysSerializable(): void
    {
        $communicator = new ApiCommunicator('https://example.com', 'token');
        $client = self::invoke($communicator, 'defaultClient');

        $clone = clone $communicator;
        $clone->setRequestTimeout(9.0);

        $this->assertInstanceOf(ClientInterface::class, self::invoke($clone, 'defaultClient'));
        $this->assertNotSame($client, self::invoke($clone, 'defaultClient'));
        $this->assertSame($client, self::invoke($communicator, 'defaultClient'));
        $this->assertInstanceOf(ApiCommunicator::class, unserialize(serialize($communicator)));
    }

    /**
     * @dataProvider insecureTransports
     */
    public function testInsecureTransportOfTheDefaultClientIsLogged(string $url, bool $verify, bool $expectWarning): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($expectWarning ? $this->once() : $this->never())
            ->method('warning')
            ->with('The connection to the Daktela API is not secure');
        $communicator = new ApiCommunicator($url, 'token');
        $communicator->setLogger($logger);
        $communicator->setVerifySsl($verify);

        self::invoke($communicator, 'defaultClient');
        self::invoke($communicator, 'defaultClient');
    }

    public function insecureTransports(): array
    {
        return [
            'https with verification' => ['https://example.com', true, false],
            'https without verification' => ['https://example.com', false, true],
            'plain http' => ['http://example.com', true, true],
        ];
    }

    /**
     * @dataProvider echoedPasswords
     */
    public function testPasswordEchoedWithOtherEscapingIsKeptOutOfALoginError(string $password, string $body): void
    {
        $logger = new class extends AbstractLogger {
            public string $text = '';

            public function log($level, $message, array $context = []): void
            {
                $this->text .= $message . ' ' . json_encode($context) . ' ' . print_r($context, true);
            }
        };
        $communicator = $this->communicator([new GuzzleResponse(401, [], $body)], 'https://example.com', '');
        $communicator->setLogger($logger);

        try {
            $communicator->login('api', $password);
            $this->fail('A RequestException was expected');
        } catch (RequestException $ex) {
            $seen = self::exposed($ex) . $ex->getResponseBody() . print_r($ex->getApiErrors(), true) . $logger->text;
            $decoded = json_decode((string)$ex->getResponseBody(), true);
            $seen .= is_array($decoded) ? print_r($decoded, true) : '';
            $forms = [
                $password,
                htmlspecialchars($password, ENT_QUOTES),
                substr((string)json_encode($password, JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG), 1, -1),
                substr((string)json_encode($password, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1),
            ];
            foreach ($forms as $form) {
                $this->assertStringNotContainsString($form, $seen);
            }
            $this->assertSame(401, $ex->getHttpStatus());
        }
    }

    public function echoedPasswords(): array
    {
        $password = 'P&ss\'w"rd</é>';
        $message = 'Invalid password ' . $password . ' for the user';

        return [
            'JSON with hex escapes' => [
                $password,
                (string)json_encode(['error' => [$message]], JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG),
            ],
            'JSON with unescaped unicode and slashes' => [
                $password,
                (string)json_encode(['error' => ['password' => $message]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
            'JSON with HTML entities' => [
                $password,
                (string)json_encode(['error' => [htmlspecialchars($message, ENT_QUOTES)], 'result' => [htmlentities($password) => 1]]),
            ],
            'HTML page of a proxy' => [
                $password,
                '<html><body><h1>Unauthorized</h1><p>' . htmlspecialchars($message, ENT_QUOTES) . '</p></body></html>',
            ],
        ];
    }

    public function testApiErrorsOfARejectedLoginAreKept(): void
    {
        $communicator = $this->communicator(
            [new GuzzleResponse(401, [], '{"error":["Invalid credentials"],"result":null}')],
            'https://example.com',
            ''
        );

        try {
            $communicator->login('api', 'password');
            $this->fail('A RequestException was expected');
        } catch (RequestException $ex) {
            $this->assertSame(['Invalid credentials'], $ex->getApiErrors());
            $this->assertSame('{"error":["Invalid credentials"],"result":null}', $ex->getResponseBody());
            $this->assertStringContainsString('Invalid credentials', $ex->getMessage());
        }
    }

    public function testLoginCredentialsAreNotArgumentsOfAFrameALoggerCanSee(): void
    {
        $logger = new class extends AbstractLogger {
            public string $arguments = '';

            public function log($level, $message, array $context = []): void
            {
                foreach (debug_backtrace() as $frame) {
                    if (str_starts_with($frame['class'] ?? '', 'Daktela\\DaktelaV6\\')) {
                        $this->arguments .= print_r($frame['args'] ?? [], true);
                    }
                }
            }
        };
        $password = 'correct horse battery staple';
        $communicator = $this->communicator([new GuzzleResponse(401, [], '{"error":["Invalid credentials"]}')], 'https://example.com', '');
        $communicator->setLogger($logger);

        try {
            $communicator->login('api', $password);
            $this->fail('A RequestException was expected');
        } catch (RequestException $ex) {
            $this->assertNotSame('', $logger->arguments);
            $this->assertStringNotContainsString($password, $logger->arguments);
            $this->assertStringContainsString($password, (string)$this->history[0]['request']->getBody());
        }
    }
}
