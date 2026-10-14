<?php

declare(strict_types=1);

namespace Daktela\Tests\DaktelaV6\Http;

use Daktela\DaktelaV6\Auth\InMemoryTokenStore;
use Daktela\DaktelaV6\Auth\TokenSet;
use Daktela\DaktelaV6\Client;
use Daktela\DaktelaV6\Http\ApiCommunicator;
use PHPUnit\Framework\TestCase;

/**
 * Runs the health check against a real Daktela instance set by the INSTANCE environment variable. The
 * static token tests need ACCESS_TOKEN, the login tests LOGIN_USERNAME and LOGIN_PASSWORD; tests whose
 * variables are empty are skipped.
 */
class HealthCheckTest extends TestCase
{
    private string $instance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance = (string)getenv('INSTANCE');
        if ($this->instance === '') {
            $this->markTestSkipped('Set INSTANCE to run the integration tests');
        }
    }

    private function accessToken(): string
    {
        $accessToken = (string)getenv('ACCESS_TOKEN');
        if ($accessToken === '') {
            $this->markTestSkipped('Set ACCESS_TOKEN to run the static token tests');
        }

        return $accessToken;
    }

    /**
     * @return string[] username and password of an account without two-factor authentication
     */
    private function credentials(): array
    {
        $username = (string)getenv('LOGIN_USERNAME');
        $password = (string)getenv('LOGIN_PASSWORD');
        if ($username === '' || $password === '') {
            $this->markTestSkipped('Set LOGIN_USERNAME and LOGIN_PASSWORD to run the login tests');
        }

        return [$username, $password];
    }

    private function communicator(string $accessToken, int $authenticationMethod): ApiCommunicator
    {
        $communicator = new ApiCommunicator($this->instance, $accessToken);
        $communicator->setAuthenticationMethod($authenticationMethod);
        $communicator->setRequestTimeout(10.0);

        return $communicator;
    }

    public function testHealthCheckAcceptsStaticToken(): void
    {
        $health = $this->communicator($this->accessToken(), ApiCommunicator::AUTHENTICATION_METHOD_HEADER)->healthCheck();

        $this->assertTrue($health['healthy'], $health['error'] ?? '');
        $this->assertSame(200, $health['status_code']);
    }

    /**
     * Requires Daktela 31.37.0, 32.36.0, 33.14.0, or later.
     */
    public function testHealthCheckAcceptsStaticTokenInBearerHeader(): void
    {
        $health = $this->communicator($this->accessToken(), ApiCommunicator::AUTHENTICATION_METHOD_BEARER)->healthCheck();

        $this->assertTrue($health['healthy'], $health['error'] ?? '');
    }

    public function testHealthCheckReportsRejectedToken(): void
    {
        $this->accessToken();
        $health = $this->communicator(md5(uniqid('', true)), ApiCommunicator::AUTHENTICATION_METHOD_HEADER)->healthCheck();

        $this->assertFalse($health['healthy']);
        $this->assertSame(200, $health['status_code']);
        $this->assertSame('The access token was not accepted: the API did not identify a user.', $health['error']);
    }

    public function testLoginWithUsernameAndPassword(): void
    {
        [$username, $password] = $this->credentials();

        $this->assertTrue(Client::login($this->instance, $username, $password)->ping());
    }

    public function testLoginSessionRefreshesAccessTokenWithRealServer(): void
    {
        [$username, $password] = $this->credentials();
        $store = new InMemoryTokenStore();
        Client::login($this->instance, $username, $password, $store);
        $issued = $store->load();
        $this->assertNotNull($issued);
        // An access token the server does not accept, next to the real refresh token
        $store->save(TokenSet::fromTokens('rejected-access-token', $issued->getRefreshToken(), $issued->getOwner()));

        $health = Client::login($this->instance, $username, $password, $store)->healthCheck();

        $this->assertTrue($health['healthy'], $health['error'] ?? '');
        $this->assertNotSame('rejected-access-token', $store->load()->getAccessToken());
    }
}
