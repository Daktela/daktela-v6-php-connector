<?php

declare(strict_types=1);

namespace Daktela\Tests\DaktelaV6\Http;

use Daktela\DaktelaV6\Client;
use Daktela\DaktelaV6\Http\ApiCommunicator;
use PHPUnit\Framework\TestCase;

/**
 * Runs the health check against a real Daktela instance, set by the INSTANCE and ACCESS_TOKEN environment
 * variables (and LOGIN_USERNAME and LOGIN_PASSWORD for the login test). Skipped when they are empty.
 */
class HealthCheckTest extends TestCase
{
    private string $instance;
    private string $accessToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance = (string)getenv('INSTANCE');
        $this->accessToken = (string)getenv('ACCESS_TOKEN');
        if ($this->instance === '' || $this->accessToken === '') {
            $this->markTestSkipped('Set INSTANCE and ACCESS_TOKEN to run the integration tests');
        }
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
        $health = $this->communicator($this->accessToken, ApiCommunicator::AUTHENTICATION_METHOD_HEADER)->healthCheck();

        $this->assertTrue($health['healthy'], $health['error'] ?? '');
        $this->assertSame(200, $health['status_code']);
    }

    /**
     * Requires Daktela 31.37.0, 32.36.0, 33.14.0, or later.
     */
    public function testHealthCheckAcceptsStaticTokenInBearerHeader(): void
    {
        $health = $this->communicator($this->accessToken, ApiCommunicator::AUTHENTICATION_METHOD_BEARER)->healthCheck();

        $this->assertTrue($health['healthy'], $health['error'] ?? '');
    }

    public function testHealthCheckReportsRejectedToken(): void
    {
        $health = $this->communicator(md5(uniqid('', true)), ApiCommunicator::AUTHENTICATION_METHOD_HEADER)->healthCheck();

        $this->assertFalse($health['healthy']);
        $this->assertSame(200, $health['status_code']);
        $this->assertSame('The access token was not accepted: the API did not identify a user.', $health['error']);
    }

    public function testLoginWithUsernameAndPassword(): void
    {
        $username = (string)getenv('LOGIN_USERNAME');
        $password = (string)getenv('LOGIN_PASSWORD');
        if ($username === '' || $password === '') {
            $this->markTestSkipped('Set LOGIN_USERNAME and LOGIN_PASSWORD to run the login test');
        }

        $this->assertTrue(Client::login($this->instance, $username, $password)->ping());
    }
}
