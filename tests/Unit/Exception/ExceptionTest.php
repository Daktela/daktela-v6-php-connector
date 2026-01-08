<?php

declare(strict_types=1);

namespace Daktela\Tests\Unit\Exception;

use Daktela\DaktelaV6\Exception\NotFoundException;
use Daktela\DaktelaV6\Exception\RateLimitException;
use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\Exception\UnknownRequestTypeException;
use Exception;
use PHPUnit\Framework\TestCase;

class ExceptionTest extends TestCase
{
    // RequestException tests
    public function testRequestExceptionMessage(): void
    {
        $exception = new RequestException('Test error message');

        $this->assertEquals('Test error message', $exception->getMessage());
    }

    public function testRequestExceptionCode(): void
    {
        $exception = new RequestException('Error', 500);

        $this->assertEquals(500, $exception->getCode());
    }

    public function testRequestExceptionPrevious(): void
    {
        $previous = new Exception('Previous exception');
        $exception = new RequestException('Error', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    // NotFoundException tests
    public function testNotFoundExceptionCodeIs404(): void
    {
        $exception = new NotFoundException('Not found');

        $this->assertEquals(404, $exception->getCode());
    }

    public function testNotFoundExceptionMessage(): void
    {
        $exception = new NotFoundException('Resource not found');

        $this->assertEquals('Resource not found', $exception->getMessage());
    }

    public function testNotFoundExceptionExtendsRequestException(): void
    {
        $exception = new NotFoundException('Not found');

        $this->assertInstanceOf(RequestException::class, $exception);
    }

    // UnknownRequestTypeException tests
    public function testUnknownRequestTypeExceptionCodeIs500(): void
    {
        $exception = new UnknownRequestTypeException();

        $this->assertEquals(500, $exception->getCode());
    }

    public function testUnknownRequestTypeExceptionDefaultMessage(): void
    {
        $exception = new UnknownRequestTypeException();

        $this->assertEquals('Unknown request type', $exception->getMessage());
    }

    public function testUnknownRequestTypeExceptionExtendsRequestException(): void
    {
        $exception = new UnknownRequestTypeException();

        $this->assertInstanceOf(RequestException::class, $exception);
    }

    // RateLimitException tests
    public function testRateLimitExceptionCodeIs429(): void
    {
        $exception = new RateLimitException(30);

        $this->assertEquals(429, $exception->getCode());
    }

    public function testRateLimitExceptionGetRetryAfterSeconds(): void
    {
        $exception = new RateLimitException(60);

        $this->assertEquals(60, $exception->getRetryAfterSeconds());
    }

    public function testRateLimitExceptionMessage(): void
    {
        $exception = new RateLimitException(30);

        $this->assertEquals('Rate limit exceeded. Retry after 30 seconds.', $exception->getMessage());
    }

    public function testRateLimitExceptionExtendsRequestException(): void
    {
        $exception = new RateLimitException(10);

        $this->assertInstanceOf(RequestException::class, $exception);
    }

    public function testRateLimitExceptionWithPrevious(): void
    {
        $previous = new Exception('Original error');
        $exception = new RateLimitException(15, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
