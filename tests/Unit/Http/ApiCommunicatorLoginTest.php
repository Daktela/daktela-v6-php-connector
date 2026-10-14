<?php

declare(strict_types=1);

namespace Daktela\Tests\Unit\Http;

use Daktela\DaktelaV6\Auth\InMemoryTokenStore;
use Daktela\DaktelaV6\Auth\TokenSet;
use Daktela\DaktelaV6\Auth\TokenStore;
use Daktela\DaktelaV6\Client as DaktelaClient;
use Daktela\DaktelaV6\Exception\RateLimitException;
use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\Http\ApiCommunicator;
use Daktela\Tests\Unit\Auth\TokenSetTest;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class ApiCommunicatorLoginTest extends TestCase
{
    /** @var string owner of the tokens of a session that logs in as api on https://example.com */
    private const OWNER = 'api@https://example.com';

    /** @var array history of the requests sent through the mock client */
    private array $history = [];
    /** @var string|false setting restored after the test */
    private $ignoreArgs = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Production configurations hide arguments in traces; the tests check the default, which keeps them
        $this->ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', (string)$this->ignoreArgs);
        parent::tearDown();
    }

    /**
     * Returns what loggers and error trackers can see of an exception: its string form and the arguments
     * of the connector's frames in the traces of the whole exception chain.
     */
    private static function exposed(\Throwable $ex): string
    {
        $text = (string)$ex;
        for ($current = $ex; $current !== null; $current = $current->getPrevious()) {
            foreach ($current->getTrace() as $frame) {
                if (str_starts_with($frame['class'] ?? '', 'Daktela\\DaktelaV6\\')) {
                    $text .= print_r($frame['args'] ?? [], true);
                }
            }
        }

        return $text;
    }

    private static function seeded(array $tokens): InMemoryTokenStore
    {
        $store = new InMemoryTokenStore();
        $store->save(TokenSet::fromTokens($tokens[0], $tokens[1], self::OWNER));

        return $store;
    }

    private function communicator(array $responses): ApiCommunicator
    {
        $this->history = [];
        $mock = new MockHandler($responses);
        $history = &$this->history;
        // Recorded at the network handler, so redirected requests are recorded too
        $stack = HandlerStack::create(static function (RequestInterface $request, array $options) use ($mock, &$history) {
            $history[] = ['request' => $request];

            return $mock($request, $options);
        });
        $communicator = new ApiCommunicator('https://example.com', '');
        $communicator->setHttpClient(new Client(['handler' => $stack]));

        return $communicator;
    }

    private static function tokens(string $name, int $accessTtl = 3600, int $refreshTtl = 86400): array
    {
        return [TokenSetTest::jwt(time() + $accessTtl) . $name, TokenSetTest::jwt(time() + $refreshTtl) . 'R' . $name];
    }

    private static function tokenResponse(array $tokens): GuzzleResponse
    {
        return new GuzzleResponse(200, [], json_encode(['error' => [], 'result' => [
            'accessToken' => $tokens[0],
            'accessTokenExpirationDate' => '2026-10-14 12:00:00',
            'refreshToken' => $tokens[1],
            'refreshTokenExpirationDate' => '2026-10-19 12:00:00',
        ]]));
    }

    private static function okResponse(): GuzzleResponse
    {
        return new GuzzleResponse(200, [], json_encode(['error' => [], 'result' => ['data' => [], 'total' => 0]]));
    }

    private function request(int $index): RequestInterface
    {
        return $this->history[$index]['request'];
    }

    public function testLoginSendsCredentialsAndUsesAccessToken(): void
    {
        $tokens = self::tokens('A');
        $communicator = $this->communicator([self::tokenResponse($tokens), self::okResponse()]);

        $communicator->login('api', 'password', null);
        $communicator->sendRequest('GET', 'Users');

        $login = $this->request(0);
        $this->assertSame('POST', $login->getMethod());
        $this->assertSame('/api/v6/login.json', $login->getUri()->getPath());
        $this->assertSame(
            ['username' => 'api', 'password' => 'password', 'only_token' => 'true', 'client_side_refresh' => 'true'],
            json_decode((string)$login->getBody(), true)
        );
        $this->assertFalse($login->hasHeader('X-AUTH-TOKEN'));
        $this->assertSame($tokens[0], $this->request(1)->getHeaderLine('X-AUTH-TOKEN'));
    }

    public function testLoginSavesTokensAndOtherProcessReusesThem(): void
    {
        $tokens = self::tokens('A');
        $store = new InMemoryTokenStore();
        $this->communicator([self::tokenResponse($tokens)])->login('api', 'password', $store);

        $communicator = $this->communicator([self::okResponse()]);
        $communicator->login('api', 'password', $store);
        $communicator->sendRequest('GET', 'Users');

        $this->assertCount(1, $this->history);
        $this->assertSame($tokens[0], $this->request(0)->getHeaderLine('X-AUTH-TOKEN'));
    }

    public function testRefreshesAccessTokenBeforeItExpires(): void
    {
        $old = self::tokens('A', 30);
        $new = self::tokens('B');
        $store = new InMemoryTokenStore();
        $store->save(TokenSet::fromTokens($old[0], $old[1], self::OWNER));
        $communicator = $this->communicator([self::tokenResponse($new), self::okResponse()]);

        $communicator->login('api', 'password', $store);
        $communicator->sendRequest('GET', 'Users');

        $refresh = $this->request(0);
        $this->assertSame('/api/v6/refreshAccessToken.json', $refresh->getUri()->getPath());
        $this->assertSame(['refresh_token' => $old[1]], json_decode((string)$refresh->getBody(), true));
        $this->assertFalse($refresh->hasHeader('X-AUTH-TOKEN'));
        $this->assertSame($new[0], $this->request(1)->getHeaderLine('X-AUTH-TOKEN'));
        $this->assertSame($new[0], $store->load()->getAccessToken());
    }

    public function testRefreshesAndRetriesOnceAfterUnauthorized(): void
    {
        $old = self::tokens('A');
        $new = self::tokens('B');
        $store = new InMemoryTokenStore();
        $store->save(TokenSet::fromTokens($old[0], $old[1], self::OWNER));
        $communicator = $this->communicator([new GuzzleResponse(401, [], '{}'), self::tokenResponse($new), self::okResponse()]);

        $communicator->login('api', 'password', $store);
        $response = $communicator->sendRequest('POST', 'Users', [], ['name' => 'alice']);

        $this->assertSame(200, $response->getHttpStatus());
        $this->assertCount(3, $this->history);
        $this->assertSame($old[0], $this->request(0)->getHeaderLine('X-AUTH-TOKEN'));
        $this->assertSame('/api/v6/refreshAccessToken.json', $this->request(1)->getUri()->getPath());
        $this->assertSame($new[0], $this->request(2)->getHeaderLine('X-AUTH-TOKEN'));
        $this->assertSame('{"name":"alice"}', (string)$this->request(2)->getBody());
    }

    public function testReportsSecondUnauthorizedWithoutLooping(): void
    {
        $old = self::tokens('A');
        $store = new InMemoryTokenStore();
        $store->save(TokenSet::fromTokens($old[0], $old[1], self::OWNER));
        $communicator = $this->communicator([
            new GuzzleResponse(401, [], '{}'),
            self::tokenResponse(self::tokens('B')),
            new GuzzleResponse(401, [], '{}'),
        ]);
        $communicator->login('api', 'password', $store);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame(401, $ex->getHttpStatus());
            $this->assertCount(3, $this->history);
        }
    }

    public function testLogsInAgainWhenRefreshIsRejected(): void
    {
        $old = self::tokens('A', 30);
        $new = self::tokens('C');
        $store = new InMemoryTokenStore();
        $store->save(TokenSet::fromTokens($old[0], $old[1], self::OWNER));
        $communicator = $this->communicator([
            new GuzzleResponse(400, [], json_encode(['error' => ['Invalid refresh token or token expired']])),
            self::tokenResponse($new),
            self::okResponse(),
        ]);

        $communicator->login('api', 'password', $store);
        $communicator->sendRequest('GET', 'Users');

        $this->assertSame('/api/v6/refreshAccessToken.json', $this->request(0)->getUri()->getPath());
        $this->assertSame('/api/v6/login.json', $this->request(1)->getUri()->getPath());
        $this->assertSame($new[0], $this->request(2)->getHeaderLine('X-AUTH-TOKEN'));
    }

    public function testLogsInWhenStoredRefreshTokenHasExpired(): void
    {
        $old = self::tokens('A', -100, -10);
        $new = self::tokens('B');
        $store = new InMemoryTokenStore();
        $store->save(TokenSet::fromTokens($old[0], $old[1], self::OWNER));
        $communicator = $this->communicator([self::tokenResponse($new), self::okResponse()]);

        $communicator->login('api', 'password', $store);
        $communicator->sendRequest('GET', 'Users');

        $this->assertSame('/api/v6/login.json', $this->request(0)->getUri()->getPath());
        $this->assertSame($new[0], $this->request(1)->getHeaderLine('X-AUTH-TOKEN'));
    }

    public function testFailedLoginDoesNotChainRequestWithPassword(): void
    {
        $communicator = $this->communicator([
            new GuzzleResponse(400, [], json_encode(['error' => ['Invalid username or password']])),
        ]);

        try {
            $communicator->login('api', 'pw-SECRET', null);
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertNull($ex->getPrevious());
            $this->assertSame(400, $ex->getHttpStatus());
            $this->assertSame(['Invalid username or password'], $ex->getApiErrors());
            $this->assertStringNotContainsString('pw-SECRET', $ex->getMessage());
        }
    }

    /**
     * @dataProvider foreignOwners
     */
    public function testStoredTokensOfAnotherSessionAreNotUsed(?string $owner): void
    {
        $foreign = self::tokens('X');
        $own = self::tokens('A');
        $store = new InMemoryTokenStore();
        $store->save(TokenSet::fromTokens($foreign[0], $foreign[1], $owner));
        $communicator = $this->communicator([self::tokenResponse($own), self::okResponse()]);

        $communicator->login('api', 'password', $store);
        $communicator->sendRequest('GET', 'Users');

        $this->assertSame('/api/v6/login.json', $this->request(0)->getUri()->getPath());
        $this->assertSame($own[0], $this->request(1)->getHeaderLine('X-AUTH-TOKEN'));
        $this->assertSame(self::OWNER, $store->load()->getOwner());
    }

    public static function foreignOwners(): array
    {
        return [
            'another user' => ['admin@https://example.com'],
            'another instance' => ['api@https://other.example.org'],
            'saved without owner' => [null],
        ];
    }

    public function testLoginDoesNotFollowRedirectsWithCredentials(): void
    {
        // A 307/308 redirect would re-send the payload, which holds the password, to the redirect target
        $communicator = $this->communicator([
            new GuzzleResponse(307, ['Location' => 'https://other.example.org/api/v6/login.json']),
            self::tokenResponse(self::tokens('A')),
        ]);

        try {
            $communicator->login('api', 'password', null);
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertCount(1, $this->history);
        }
    }

    public function testRefreshDoesNotFollowRedirectsWithRefreshToken(): void
    {
        $old = self::tokens('A', -10);
        $store = new InMemoryTokenStore();
        $store->save(TokenSet::fromTokens($old[0], $old[1], self::OWNER));
        $communicator = $this->communicator([
            new GuzzleResponse(308, ['Location' => 'https://other.example.org/api/v6/refreshAccessToken.json']),
            self::tokenResponse(self::tokens('B')),
        ]);
        $communicator->login('api', 'password', $store);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertCount(1, $this->history);
            $this->assertSame('/api/v6/refreshAccessToken.json', $this->request(0)->getUri()->getPath());
        }
    }

    public function testFailedLoginKeepsPasswordOutOfTraces(): void
    {
        $communicator = $this->communicator([new GuzzleResponse(500, [], '{}')]);

        try {
            $communicator->login('api', 'pw-SECRET-123', null);
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringNotContainsString('pw-SECRET', self::exposed($ex));
        }
    }

    public function testFailedClientLoginKeepsPasswordOutOfTraces(): void
    {
        try {
            // Nothing listens on the discard port, so the connection is refused at once
            DaktelaClient::login('http://127.0.0.1:9', 'api', 'pw-SECRET-123');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringNotContainsString('pw-SECRET', self::exposed($ex));
        }
    }

    public function testFailedRefreshKeepsRefreshTokenOutOfTraces(): void
    {
        $old = self::tokens('A', -10);
        $communicator = $this->communicator([new GuzzleResponse(500, [], '{}')]);
        $communicator->login('api', 'password', self::seeded($old));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringNotContainsString($old[1], self::exposed($ex));
            $this->assertStringNotContainsString(substr($old[1], 0, 12), self::exposed($ex));
        }
    }

    public function testFailedLoginAfterRejectedRefreshKeepsPasswordOutOfTraces(): void
    {
        $communicator = $this->communicator([
            new GuzzleResponse(400, [], json_encode(['error' => ['Invalid refresh token or token expired']])),
            new GuzzleResponse(500, [], '{}'),
        ]);
        $communicator->login('api', 'pw-SECRET-123', self::seeded(self::tokens('A', -10)));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringNotContainsString('pw-SECRET', self::exposed($ex));
        }
    }

    public function testPasswordThatIsNotValidUtf8IsNotLeaked(): void
    {
        $communicator = $this->communicator([]);

        try {
            $communicator->login('api', "pw-SECRET-\xB1", null);
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringStartsWith('The request payload cannot be encoded as JSON', $ex->getMessage());
            $this->assertStringNotContainsString('pw-SECRET', self::exposed($ex));
            $this->assertCount(0, $this->history);
        }
    }

    public function testUnexpectedErrorDuringLoginKeepsPasswordOutOfTraces(): void
    {
        // e.g. a logger that cannot open its log file
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->method('debug')->willThrowException(new \UnexpectedValueException('The log file cannot be opened'));
        $communicator = $this->communicator([self::tokenResponse(self::tokens('A'))]);
        $communicator->setLogger($logger);

        try {
            $communicator->login('api', 'pw-SECRET-123', null);
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame('The log file cannot be opened', $ex->getMessage());
            $this->assertNull($ex->getPrevious());
            $this->assertStringNotContainsString('pw-SECRET', self::exposed($ex));
        }
    }

    public function testDebugOutputHidesCredentialsAndTokens(): void
    {
        $tokens = self::tokens('A');
        $communicator = $this->communicator([self::tokenResponse($tokens)]);
        $communicator->login('api', 'pw-SECRET-123', null);
        $client = new DaktelaClient('https://example.com', '', $communicator);

        foreach ([print_r($communicator, true), print_r($client, true), print_r(TokenSet::fromTokens($tokens[0], $tokens[1]), true)] as $dump) {
            $this->assertStringNotContainsString('pw-SECRET', $dump);
            $this->assertStringNotContainsString($tokens[0], $dump);
            $this->assertStringNotContainsString($tokens[1], $dump);
        }
    }

    public function testRateLimitDuringLoginKeepsRateLimitException(): void
    {
        $communicator = $this->communicator([new GuzzleResponse(429, ['Retry-After' => '7'])]);

        try {
            $communicator->login('api', 'password', null);
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $ex) {
            $this->assertSame(7, $ex->getRetryAfterSeconds());
            $this->assertNull($ex->getPrevious());
        }
    }

    public function testFailedSecondLoginDropsPreviousSession(): void
    {
        $communicator = $this->communicator([
            self::tokenResponse(self::tokens('A')),
            new GuzzleResponse(400, [], json_encode(['error' => ['Invalid username or password']])),
        ]);
        $communicator->login('api', 'password', null);
        $storeB = new InMemoryTokenStore();

        try {
            $communicator->login('userB', 'wrong', $storeB);
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
        }

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertCount(2, $this->history);
            $this->assertNull($storeB->load());
        }
    }

    public function testRejectedLoginIsNotRepeatedUntilLoginIsCalledAgain(): void
    {
        $communicator = $this->communicator([
            new GuzzleResponse(400, [], json_encode(['error' => ['Invalid refresh token or token expired']])),
            new GuzzleResponse(400, [], json_encode(['error' => ['Invalid username or password']])),
        ]);
        $communicator->login('api', 'changed', self::seeded(self::tokens('A', -10)));

        foreach ([1, 2, 3] as $_) {
            try {
                $communicator->sendRequest('GET', 'Users');
                $this->fail('Expected RequestException');
            } catch (RequestException $ex) {
            }
        }
        $this->assertFalse($communicator->ping());
        $this->assertCount(2, $this->history);
    }

    public function testInvalidJsonTokenResponseDoesNotExposeTokens(): void
    {
        $tokens = self::tokens('A');
        $body = 'Notice: something' . (string)self::tokenResponse($tokens)->getBody();
        $communicator = $this->communicator([new GuzzleResponse(200, [], $body)]);

        try {
            $communicator->login('api', 'password', null);
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertNull($ex->getResponseBody());
            $this->assertStringNotContainsString($tokens[0], self::exposed($ex));
        }
    }

    public function testRefreshWithoutNewRefreshTokenKeepsCurrentOne(): void
    {
        $old = self::tokens('A', 30);
        $new = self::tokens('B');
        $store = self::seeded($old);
        $communicator = $this->communicator([
            new GuzzleResponse(200, [], json_encode(['error' => [], 'result' => ['accessToken' => $new[0]]])),
            self::okResponse(),
        ]);

        $communicator->login('api', 'password', $store);
        $communicator->sendRequest('GET', 'Users');

        $this->assertSame($new[0], $this->request(1)->getHeaderLine('X-AUTH-TOKEN'));
        $this->assertSame($old[1], $store->load()->getRefreshToken());
    }

    public function testFailedProactiveRefreshUsesStillValidAccessToken(): void
    {
        $old = self::tokens('A', 30);
        $communicator = $this->communicator([new GuzzleResponse(503, [], '{}'), self::okResponse()]);
        $communicator->login('api', 'password', self::seeded($old));

        $response = $communicator->sendRequest('GET', 'Users');

        $this->assertSame(200, $response->getHttpStatus());
        $this->assertSame($old[0], $this->request(1)->getHeaderLine('X-AUTH-TOKEN'));
    }

    public function testFailingTokenStoreDoesNotBreakTheSession(): void
    {
        $store = new class implements TokenStore {
            public function load(): ?TokenSet
            {
                throw new \RuntimeException('cache down');
            }

            public function save(TokenSet $tokens): void
            {
                throw new \RuntimeException('cache down');
            }
        };
        $tokens = self::tokens('A');
        $communicator = $this->communicator([self::tokenResponse($tokens), self::okResponse()]);

        $communicator->login('api', 'password', $store);
        $communicator->sendRequest('GET', 'Users');

        $this->assertSame($tokens[0], $this->request(1)->getHeaderLine('X-AUTH-TOKEN'));
    }

    public function testHealthCheckRefreshesSessionTheServerEnded(): void
    {
        $new = self::tokens('B');
        $whoim = static fn (?array $user): GuzzleResponse => new GuzzleResponse(200, [], json_encode([
            'error' => [],
            'result' => ['version' => ['current' => '2026.1'], 'user' => $user],
        ]));
        $communicator = $this->communicator([$whoim(null), self::tokenResponse($new), $whoim(['name' => 'api'])]);
        $communicator->login('api', 'password', self::seeded(self::tokens('A')));

        $this->assertTrue($communicator->healthCheck()['healthy']);
        $this->assertSame($new[0], $this->request(2)->getHeaderLine('X-AUTH-TOKEN'));
    }

    public function testPreviousAccessTokenIsRedactedAfterRefresh(): void
    {
        $old = self::tokens('A', 30);
        $communicator = $this->communicator([
            self::tokenResponse(self::tokens('B')),
            new GuzzleResponse(400, [], json_encode(['error' => ['Token ' . $old[0] . ' was replaced']])),
        ]);
        $communicator->login('api', 'password', self::seeded($old));

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame(['Token *** was replaced'], $ex->getApiErrors());
        }
    }

    public function testLogsInAgainWhenRefreshIsUnauthorized(): void
    {
        $new = self::tokens('C');
        $communicator = $this->communicator([new GuzzleResponse(401, [], '{}'), self::tokenResponse($new), self::okResponse()]);
        $communicator->login('api', 'password', self::seeded(self::tokens('A', 30)));

        $communicator->sendRequest('GET', 'Users');

        $this->assertSame('/api/v6/login.json', $this->request(1)->getUri()->getPath());
        $this->assertSame($new[0], $this->request(2)->getHeaderLine('X-AUTH-TOKEN'));
    }

    public function testClockAheadOfServerDoesNotRefreshBeforeEveryRequest(): void
    {
        // The tokens look expired by the local clock as soon as they arrive
        $communicator = $this->communicator([self::tokenResponse(self::tokens('A', -100)), self::okResponse(), self::okResponse()]);
        $communicator->login('api', 'password', null);

        $communicator->sendRequest('GET', 'Users');
        $communicator->sendRequest('GET', 'Users');

        $this->assertCount(3, $this->history);
    }

    public function testNextRequestLogsInAfterTransientLoginFailure(): void
    {
        $tokens = self::tokens('A');
        $communicator = $this->communicator([new GuzzleResponse(503, [], '{}'), self::tokenResponse($tokens), self::okResponse()]);

        try {
            $communicator->login('api', 'password', null);
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertSame(503, $ex->getHttpStatus());
        }
        $communicator->sendRequest('GET', 'Users');

        $this->assertSame('/api/v6/login.json', $this->request(1)->getUri()->getPath());
        $this->assertSame($tokens[0], $this->request(2)->getHeaderLine('X-AUTH-TOKEN'));
    }

    public function testLogsInWhenRefreshTokenExpiresDuringSession(): void
    {
        $new = self::tokens('B');
        // The refresh token is valid when the session starts and expires before the access token is refreshed
        $store = new InMemoryTokenStore();
        $store->save(TokenSet::fromTokens(TokenSetTest::jwt(time() - 10), TokenSetTest::jwt(time() + 1), self::OWNER));
        $communicator = $this->communicator([self::tokenResponse($new), self::okResponse()]);
        $communicator->login('api', 'password', $store);
        sleep(2);

        $communicator->sendRequest('GET', 'Users');

        $this->assertSame('/api/v6/login.json', $this->request(0)->getUri()->getPath());
        $this->assertSame($new[0], $this->request(1)->getHeaderLine('X-AUTH-TOKEN'));
    }

    public function testNotFoundDuringLoginKeepsNotFoundException(): void
    {
        $communicator = $this->communicator([new GuzzleResponse(404, [], '{}')]);

        try {
            $communicator->login('api', 'password', null);
            $this->fail('Expected NotFoundException');
        } catch (\Daktela\DaktelaV6\Exception\NotFoundException $ex) {
            $this->assertNull($ex->getPrevious());
        }
    }

    public function testLoginResponseWithoutTokensIsRejected(): void
    {
        $communicator = $this->communicator([new GuzzleResponse(200, [], json_encode(['error' => [], 'result' => true]))]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('The login response (HTTP 200) does not contain an access token and a refresh token.');
        $communicator->login('api', 'password', null);
    }

    public function testRefreshTokenIsRedactedInErrors(): void
    {
        $tokens = self::tokens('A');
        $communicator = $this->communicator([
            self::tokenResponse($tokens),
            new GuzzleResponse(400, [], json_encode(['error' => ['Session ' . $tokens[1] . ' is closed']])),
        ]);
        $communicator->login('api', 'password', null);

        try {
            $communicator->sendRequest('GET', 'Users');
            $this->fail('Expected RequestException');
        } catch (RequestException $ex) {
            $this->assertStringNotContainsString($tokens[1], $ex->getMessage());
            $this->assertSame(['Session *** is closed'], $ex->getApiErrors());
        }
    }

    public function testStaticTokenIsNotRefreshedAfterUnauthorized(): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([new GuzzleResponse(401, [], '{}')]));
        $stack->push(Middleware::history($this->history));
        $communicator = new ApiCommunicator('https://example.com', 'static-token');
        $communicator->setHttpClient(new Client(['handler' => $stack]));

        $this->expectException(RequestException::class);
        try {
            $communicator->sendRequest('GET', 'Users');
        } finally {
            $this->assertCount(1, $this->history);
        }
    }
}
