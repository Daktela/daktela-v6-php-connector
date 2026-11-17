<?php

declare(strict_types=1);

namespace Daktela\Tests\Unit\Request;

use Daktela\DaktelaV6\Request\ReadRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ARequest abstract class using ReadRequest as concrete implementation.
 */
class ARequestTest extends TestCase
{
    public function testGetModel(): void
    {
        $request = new ReadRequest('Users');

        $this->assertEquals('Users', $request->getModel());
    }

    public function testAddAdditionalQueryParameter(): void
    {
        $request = new ReadRequest('Users');
        $result = $request->addAdditionalQueryParameter('custom', 'value');

        // Test fluent interface
        $this->assertSame($request, $result);

        $params = $request->getAdditionalQueryParameters();
        $this->assertEquals(['custom' => 'value'], $params);
    }

    public function testAddMultipleAdditionalQueryParameters(): void
    {
        $request = new ReadRequest('Users');
        $request->addAdditionalQueryParameter('param1', 'value1');
        $request->addAdditionalQueryParameter('param2', 'value2');

        $params = $request->getAdditionalQueryParameters();
        $this->assertEquals([
            'param1' => 'value1',
            'param2' => 'value2',
        ], $params);
    }

    public function testGetAdditionalQueryParametersDefaultEmpty(): void
    {
        $request = new ReadRequest('Users');

        $this->assertEquals([], $request->getAdditionalQueryParameters());
    }

    public function testAddAdditionalQueryParameterOverwrites(): void
    {
        $request = new ReadRequest('Users');
        $request->addAdditionalQueryParameter('key', 'value1');
        $request->addAdditionalQueryParameter('key', 'value2');

        $params = $request->getAdditionalQueryParameters();
        $this->assertEquals(['key' => 'value2'], $params);
    }
}
