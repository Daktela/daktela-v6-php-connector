<?php

declare(strict_types=1);

namespace Daktela\Tests\Unit\Request;

use Daktela\DaktelaV6\Request\CreateRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ARequestWithAttributes using CreateRequest as concrete implementation.
 */
class ARequestWithAttributesTest extends TestCase
{
    public function testAddAttribute(): void
    {
        $request = new CreateRequest('Users');
        $result = $request->addAttribute('name', 'John');

        $this->assertSame($request, $result);
        $this->assertEquals(['name' => 'John'], $request->getAttributes());
    }

    public function testAddStringAttribute(): void
    {
        $request = new CreateRequest('Users');
        $result = $request->addStringAttribute('name', 'John');

        $this->assertSame($request, $result);
        $this->assertEquals(['name' => 'John'], $request->getAttributes());
    }

    public function testAddIntAttribute(): void
    {
        $request = new CreateRequest('Users');
        $result = $request->addIntAttribute('age', 25);

        $this->assertSame($request, $result);
        $this->assertEquals(['age' => 25], $request->getAttributes());
    }

    public function testAddFloatAttribute(): void
    {
        $request = new CreateRequest('Users');
        $result = $request->addFloatAttribute('score', 95.5);

        $this->assertSame($request, $result);
        $this->assertEquals(['score' => 95.5], $request->getAttributes());
    }

    public function testAddDoubleAttribute(): void
    {
        $request = new CreateRequest('Users');
        $result = $request->addDoubleAttribute('price', 199.99);

        $this->assertSame($request, $result);
        $this->assertEquals(['price' => 199.99], $request->getAttributes());
    }

    public function testAddBoolAttribute(): void
    {
        $request = new CreateRequest('Users');
        $result = $request->addBoolAttribute('active', true);

        $this->assertSame($request, $result);
        $this->assertEquals(['active' => true], $request->getAttributes());
    }

    public function testAddBoolAttributeFalse(): void
    {
        $request = new CreateRequest('Users');
        $request->addBoolAttribute('active', false);

        $this->assertEquals(['active' => false], $request->getAttributes());
    }

    public function testAddArrayAttribute(): void
    {
        $request = new CreateRequest('Users');
        $result = $request->addArrayAttribute('roles', ['admin', 'user']);

        $this->assertSame($request, $result);
        $this->assertEquals(['roles' => ['admin', 'user']], $request->getAttributes());
    }

    public function testAddAttributes(): void
    {
        $request = new CreateRequest('Users');
        $result = $request->addAttributes([
            'name' => 'John',
            'age' => 25,
            'active' => true,
            'score' => 95.5,
            'roles' => ['admin'],
        ]);

        $this->assertSame($request, $result);

        $attributes = $request->getAttributes();
        $this->assertEquals('John', $attributes['name']);
        $this->assertEquals(25, $attributes['age']);
        $this->assertTrue($attributes['active']);
        $this->assertEquals(95.5, $attributes['score']);
        $this->assertEquals(['admin'], $attributes['roles']);
    }

    public function testGetAttributesDefaultEmpty(): void
    {
        $request = new CreateRequest('Users');

        $this->assertEquals([], $request->getAttributes());
    }

    public function testMultipleAttributes(): void
    {
        $request = new CreateRequest('Users');
        $request->addStringAttribute('name', 'John');
        $request->addIntAttribute('age', 30);
        $request->addBoolAttribute('active', true);

        $this->assertEquals([
            'name' => 'John',
            'age' => 30,
            'active' => true,
        ], $request->getAttributes());
    }

    public function testAttributeOverwrite(): void
    {
        $request = new CreateRequest('Users');
        $request->addStringAttribute('name', 'John');
        $request->addStringAttribute('name', 'Jane');

        $this->assertEquals(['name' => 'Jane'], $request->getAttributes());
    }

    public function testFluentInterface(): void
    {
        $request = new CreateRequest('Users');

        $result = $request
            ->addStringAttribute('name', 'John')
            ->addIntAttribute('age', 25)
            ->addBoolAttribute('active', true)
            ->addArrayAttribute('roles', ['user']);

        $this->assertSame($request, $result);
        $this->assertCount(4, $request->getAttributes());
    }
}
