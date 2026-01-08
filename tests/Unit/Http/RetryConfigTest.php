<?php

declare(strict_types=1);

namespace Daktela\Tests\Unit\Http;

use Daktela\DaktelaV6\Http\RetryConfig;
use PHPUnit\Framework\TestCase;

class RetryConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new RetryConfig();

        $this->assertEquals(3, $config->getMaxRetries());
        $this->assertEquals(100, $config->getBaseDelayMs());
        $this->assertEquals(10000, $config->getMaxDelayMs());
        $this->assertEquals(2.0, $config->getMultiplier());
        $this->assertEquals([408, 500, 502, 503, 504], $config->getRetryableStatusCodes());
        $this->assertTrue($config->shouldRetryOnConnectionError());
    }

    public function testCustomValues(): void
    {
        $config = new RetryConfig(
            maxRetries: 5,
            baseDelayMs: 200,
            maxDelayMs: 5000,
            multiplier: 1.5,
            retryableStatusCodes: [500, 503],
            retryOnConnectionError: false,
        );

        $this->assertEquals(5, $config->getMaxRetries());
        $this->assertEquals(200, $config->getBaseDelayMs());
        $this->assertEquals(5000, $config->getMaxDelayMs());
        $this->assertEquals(1.5, $config->getMultiplier());
        $this->assertEquals([500, 503], $config->getRetryableStatusCodes());
        $this->assertFalse($config->shouldRetryOnConnectionError());
    }

    public function testGetDelayForAttemptFirstAttempt(): void
    {
        $config = new RetryConfig(baseDelayMs: 100, multiplier: 2.0);

        // First attempt (0): 100 * 2^0 = 100
        $this->assertEquals(100, $config->getDelayForAttempt(0));
    }

    public function testGetDelayForAttemptSecondAttempt(): void
    {
        $config = new RetryConfig(baseDelayMs: 100, multiplier: 2.0);

        // Second attempt (1): 100 * 2^1 = 200
        $this->assertEquals(200, $config->getDelayForAttempt(1));
    }

    public function testGetDelayForAttemptThirdAttempt(): void
    {
        $config = new RetryConfig(baseDelayMs: 100, multiplier: 2.0);

        // Third attempt (2): 100 * 2^2 = 400
        $this->assertEquals(400, $config->getDelayForAttempt(2));
    }

    public function testGetDelayForAttemptRespectsMaxDelay(): void
    {
        $config = new RetryConfig(baseDelayMs: 1000, maxDelayMs: 2000, multiplier: 3.0);

        // Attempt 2: 1000 * 3^2 = 9000, but max is 2000
        $this->assertEquals(2000, $config->getDelayForAttempt(2));
    }

    public function testGetDelayForAttemptExponentialGrowth(): void
    {
        $config = new RetryConfig(baseDelayMs: 100, maxDelayMs: 100000, multiplier: 2.0);

        $this->assertEquals(100, $config->getDelayForAttempt(0));   // 100 * 2^0
        $this->assertEquals(200, $config->getDelayForAttempt(1));   // 100 * 2^1
        $this->assertEquals(400, $config->getDelayForAttempt(2));   // 100 * 2^2
        $this->assertEquals(800, $config->getDelayForAttempt(3));   // 100 * 2^3
        $this->assertEquals(1600, $config->getDelayForAttempt(4));  // 100 * 2^4
    }

    public function testIsRetryableStatusReturnsTrue(): void
    {
        $config = new RetryConfig();

        $this->assertTrue($config->isRetryableStatus(500));
        $this->assertTrue($config->isRetryableStatus(502));
        $this->assertTrue($config->isRetryableStatus(503));
        $this->assertTrue($config->isRetryableStatus(504));
        $this->assertTrue($config->isRetryableStatus(408));
    }

    public function testIsRetryableStatusReturnsFalse(): void
    {
        $config = new RetryConfig();

        $this->assertFalse($config->isRetryableStatus(200));
        $this->assertFalse($config->isRetryableStatus(400));
        $this->assertFalse($config->isRetryableStatus(401));
        $this->assertFalse($config->isRetryableStatus(403));
        $this->assertFalse($config->isRetryableStatus(404));
        $this->assertFalse($config->isRetryableStatus(429));
    }

    public function testDisabledFactory(): void
    {
        $config = RetryConfig::disabled();

        $this->assertEquals(0, $config->getMaxRetries());
    }

    public function testAggressiveFactory(): void
    {
        $config = RetryConfig::aggressive();

        $this->assertEquals(5, $config->getMaxRetries());
        $this->assertEquals(50, $config->getBaseDelayMs());
        $this->assertEquals(30000, $config->getMaxDelayMs());
        $this->assertEquals(2.5, $config->getMultiplier());
    }
}
