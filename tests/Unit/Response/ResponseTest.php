<?php

declare(strict_types=1);

namespace Daktela\Tests\Unit\Response;

use Daktela\DaktelaV6\Response\Response;
use PHPUnit\Framework\TestCase;
use stdClass;

class ResponseTest extends TestCase
{
    public function testGetData(): void
    {
        $data = ['id' => 1, 'name' => 'Test'];
        $response = new Response($data, 1, [], 200);

        $this->assertEquals($data, $response->getData());
    }

    public function testGetDataWithNull(): void
    {
        $response = new Response(null, 0, [], 200);

        $this->assertNull($response->getData());
    }

    public function testGetTotal(): void
    {
        $response = new Response([], 42, [], 200);

        $this->assertEquals(42, $response->getTotal());
    }

    public function testGetErrors(): void
    {
        $errors = ['Invalid field', 'Missing required parameter'];
        $response = new Response(null, 0, $errors, 400);

        $this->assertEquals($errors, $response->getErrors());
    }

    public function testGetErrorsEmpty(): void
    {
        $response = new Response([], 0, [], 200);

        $this->assertEquals([], $response->getErrors());
    }

    public function testGetHttpStatus(): void
    {
        $response = new Response([], 0, [], 201);

        $this->assertEquals(201, $response->getHttpStatus());
    }

    public function testIsSuccessWithStatus200(): void
    {
        $response = new Response([], 0, [], 200);

        $this->assertTrue($response->isSuccess());
    }

    public function testIsSuccessWithStatus201(): void
    {
        $response = new Response([], 0, [], 201);

        $this->assertTrue($response->isSuccess());
    }

    public function testIsSuccessWithStatus204(): void
    {
        $response = new Response([], 0, [], 204);

        $this->assertTrue($response->isSuccess());
    }

    public function testIsSuccessWithStatus299(): void
    {
        $response = new Response([], 0, [], 299);

        $this->assertTrue($response->isSuccess());
    }

    public function testIsSuccessReturnsFalseForStatus300(): void
    {
        $response = new Response([], 0, [], 300);

        $this->assertFalse($response->isSuccess());
    }

    public function testIsSuccessReturnsFalseForStatus400(): void
    {
        $response = new Response([], 0, [], 400);

        $this->assertFalse($response->isSuccess());
    }

    public function testIsSuccessReturnsFalseForStatus404(): void
    {
        $response = new Response([], 0, [], 404);

        $this->assertFalse($response->isSuccess());
    }

    public function testIsSuccessReturnsFalseForStatus500(): void
    {
        $response = new Response([], 0, [], 500);

        $this->assertFalse($response->isSuccess());
    }

    public function testIsSuccessReturnsFalseForStatus199(): void
    {
        $response = new Response([], 0, [], 199);

        $this->assertFalse($response->isSuccess());
    }

    public function testHasErrorsReturnsTrueWhenErrors(): void
    {
        $response = new Response([], 0, ['error'], 400);

        $this->assertTrue($response->hasErrors());
    }

    public function testHasErrorsReturnsFalseWhenNoErrors(): void
    {
        $response = new Response([], 0, [], 200);

        $this->assertFalse($response->hasErrors());
    }

    public function testGetFirstErrorReturnsFirstError(): void
    {
        $response = new Response([], 0, ['First error', 'Second error'], 400);

        $this->assertEquals('First error', $response->getFirstError());
    }

    public function testGetFirstErrorReturnsNullWhenNoErrors(): void
    {
        $response = new Response([], 0, [], 200);

        $this->assertNull($response->getFirstError());
    }

    public function testGetFirstErrorWithObjectError(): void
    {
        $error = new stdClass();
        $error->message = 'Error message';
        $response = new Response([], 0, [$error], 400);

        $this->assertEquals($error, $response->getFirstError());
    }

    public function testIsEmptyWithNullData(): void
    {
        $response = new Response(null, 0, [], 200);

        $this->assertTrue($response->isEmpty());
    }

    public function testIsEmptyWithEmptyArray(): void
    {
        $response = new Response([], 0, [], 200);

        $this->assertTrue($response->isEmpty());
    }

    public function testIsEmptyWithEmptyObject(): void
    {
        $response = new Response(new stdClass(), 0, [], 200);

        $this->assertTrue($response->isEmpty());
    }

    public function testIsEmptyReturnsFalseWithArrayData(): void
    {
        $response = new Response(['id' => 1], 1, [], 200);

        $this->assertFalse($response->isEmpty());
    }

    public function testIsEmptyReturnsFalseWithObjectData(): void
    {
        $obj = new stdClass();
        $obj->id = 1;
        $response = new Response($obj, 1, [], 200);

        $this->assertFalse($response->isEmpty());
    }

    public function testIsEmptyReturnsFalseWithScalarData(): void
    {
        $response = new Response('some string', 1, [], 200);

        $this->assertFalse($response->isEmpty());
    }
}
