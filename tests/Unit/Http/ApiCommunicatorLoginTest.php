<?php

declare(strict_types=1);

namespace Daktela\Tests\Unit\Http;

use Daktela\DaktelaV6\Auth\InMemoryTokenStore;
use Daktela\DaktelaV6\Auth\TokenSet;
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
        $old = self::tokens('A', 30);
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

    public function testLoginResponseWithoutTokensIsRejected(): void
    {
        $communicator = $this->communicator([new GuzzleResponse(200, [], json_encode(['error' => [], 'result' => true]))]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('The login response does not contain an access token and a refresh token.');
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
