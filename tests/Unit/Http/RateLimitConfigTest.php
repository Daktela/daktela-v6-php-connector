<?php

declare(strict_types=1);

namespace Daktela\Tests\Unit\Http;

use Daktela\DaktelaV6\Http\RateLimitConfig;
use PHPUnit\Framework\TestCase;

class RateLimitConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new RateLimitConfig();

        $this->assertTrue($config->shouldAutoRetry());
        $this->assertEquals(60, $config->getMaxWaitSeconds());
        $this->assertEquals(5, $config->getDefaultWaitSeconds());
    }

    public function testCustomValues(): void
    {
        $config = new RateLimitConfig(
            autoRetry: false,
            maxWaitSeconds: 120,
            defaultWaitSeconds: 10,
        );

        $this->assertFalse($config->shouldAutoRetry());
        $this->assertEquals(120, $config->getMaxWaitSeconds());
        $this->assertEquals(10, $config->getDefaultWaitSeconds());
    }

    public function testParseRetryAfterWithInteger(): void
    {
        $config = new RateLimitConfig();

        $this->assertEquals(30, $config->parseRetryAfter('30'));
        $this->assertEquals(1, $config->parseRetryAfter('1'));
        $this->assertEquals(0, $config->parseRetryAfter('0'));
        $this->assertEquals(3600, $config->parseRetryAfter('3600'));
    }

    public function testParseRetryAfterWithNull(): void
    {
        $config = new RateLimitConfig(defaultWaitSeconds: 10);

        $this->assertEquals(10, $config->parseRetryAfter(null));
    }

    public function testParseRetryAfterWithEmptyString(): void
    {
        $config = new RateLimitConfig(defaultWaitSeconds: 15);

        $this->assertEquals(15, $config->parseRetryAfter(''));
    }

    public function testParseRetryAfterWithHttpDate(): void
    {
        $config = new RateLimitConfig();

        // Use a date 60 seconds in the future
        $futureDate = gmdate('D, d M Y H:i:s', time() + 60) . ' GMT';
        $result = $config->parseRetryAfter($futureDate);

        // Allow some tolerance for execution time
        $this->assertGreaterThanOrEqual(58, $result);
        $this->assertLessThanOrEqual(62, $result);
    }

    public function testParseRetryAfterWithPastHttpDate(): void
    {
        $config = new RateLimitConfig();

        // Use a date in the past
        $pastDate = gmdate('D, d M Y H:i:s', time() - 60) . ' GMT';
        $result = $config->parseRetryAfter($pastDate);

        // Should return 0 for past dates
        $this->assertEquals(0, $result);
    }

    public function testParseRetryAfterWithInvalidValue(): void
    {
        $config = new RateLimitConfig(defaultWaitSeconds: 7);

        $this->assertEquals(7, $config->parseRetryAfter('invalid'));
        $this->assertEquals(7, $config->parseRetryAfter('not a number or date'));
    }

    public function testParseRetryAfterWithNegativeNumber(): void
    {
        $config = new RateLimitConfig();

        // Negative numbers are treated as valid integers
        $this->assertEquals(-5, $config->parseRetryAfter('-5'));
    }

    public function testParseRetryAfterWithDecimal(): void
    {
        $config = new RateLimitConfig();

        // Decimal numbers are truncated to integers
        $this->assertEquals(10, $config->parseRetryAfter('10.5'));
        $this->assertEquals(10, $config->parseRetryAfter('10.9'));
    }
}
