<?php

declare(strict_types=1);

namespace Daktela\Tests\Unit\Request;

use Daktela\DaktelaV6\Request\ReadRequest;
use PHPUnit\Framework\TestCase;

class ReadRequestTest extends TestCase
{
    public function testDefaultRequestTypeIsMultiple(): void
    {
        $request = new ReadRequest('Users');

        $this->assertEquals(ReadRequest::TYPE_MULTIPLE, $request->getRequestType());
    }

    public function testSetRequestTypeSingle(): void
    {
        $request = new ReadRequest('Users');
        $result = $request->setRequestType(ReadRequest::TYPE_SINGLE);

        $this->assertSame($request, $result);
        $this->assertEquals(ReadRequest::TYPE_SINGLE, $request->getRequestType());
    }

    public function testSetRequestTypeAll(): void
    {
        $request = new ReadRequest('Users');
        $request->setRequestType(ReadRequest::TYPE_ALL);

        $this->assertEquals(ReadRequest::TYPE_ALL, $request->getRequestType());
    }

    public function testDefaultTakeIs100(): void
    {
        $request = new ReadRequest('Users');

        $this->assertEquals(100, $request->getTake());
    }

    public function testSetTake(): void
    {
        $request = new ReadRequest('Users');
        $result = $request->setTake(500);

        $this->assertSame($request, $result);
        $this->assertEquals(500, $request->getTake());
    }

    public function testDefaultSkipIs0(): void
    {
        $request = new ReadRequest('Users');

        $this->assertEquals(0, $request->getSkip());
    }

    public function testSetSkip(): void
    {
        $request = new ReadRequest('Users');
        $result = $request->setSkip(100);

        $this->assertSame($request, $result);
        $this->assertEquals(100, $request->getSkip());
    }

    public function testSetObjectName(): void
    {
        $request = new ReadRequest('Users');
        $result = $request->setObjectName('user_123');

        $this->assertSame($request, $result);
        $this->assertEquals('user_123', $request->getObjectName());
    }

    public function testGetObjectNameDefaultNull(): void
    {
        $request = new ReadRequest('Users');

        $this->assertNull($request->getObjectName());
    }

    public function testSetRelation(): void
    {
        $request = new ReadRequest('Users');
        $result = $request->setRelation('Activities');

        $this->assertSame($request, $result);
        // Note: setRelation applies lcfirst
        $this->assertEquals('activities', $request->getRelation());
    }

    public function testGetRelationDefaultNull(): void
    {
        $request = new ReadRequest('Users');

        $this->assertNull($request->getRelation());
    }

    public function testSetFields(): void
    {
        $request = new ReadRequest('Users');
        $fields = ['name', 'email', 'created'];
        $result = $request->setFields($fields);

        $this->assertSame($request, $result);
        $this->assertEquals($fields, $request->getFields());
    }

    public function testGetFieldsDefaultEmpty(): void
    {
        $request = new ReadRequest('Users');

        $this->assertEquals([], $request->getFields());
    }

    public function testAddFilter(): void
    {
        $request = new ReadRequest('Users');
        $result = $request->addFilter('name', 'eq', 'John');

        $this->assertSame($request, $result);

        $filters = $request->getFilters();
        $this->assertEquals('and', $filters['logic']);
        $this->assertCount(1, $filters['filters']);
        $this->assertEquals([
            'field' => 'name',
            'operator' => 'eq',
            'value' => 'John',
        ], $filters['filters'][0]);
    }

    public function testAddMultipleFilters(): void
    {
        $request = new ReadRequest('Users');
        $request->addFilter('name', 'eq', 'John');
        $request->addFilter('active', 'eq', '1');

        $filters = $request->getFilters();
        $this->assertCount(2, $filters['filters']);
    }

    public function testAddFilterWithArrayValue(): void
    {
        $request = new ReadRequest('Users');
        $request->addFilter('status', 'in', ['active', 'pending']);

        $filters = $request->getFilters();
        $this->assertEquals(['active', 'pending'], $filters['filters'][0]['value']);
    }

    public function testAddFilterFromArray(): void
    {
        $request = new ReadRequest('Users');
        $result = $request->addFilterFromArray([
            ['field' => 'name', 'operator' => 'eq', 'value' => 'John'],
            ['field' => 'active', 'operator' => 'eq', 'value' => '1'],
        ]);

        $this->assertSame($request, $result);

        $filters = $request->getFilters();
        $this->assertCount(2, $filters['filters']);
    }

    public function testAddFilterFromArrayWithShorthandSyntax(): void
    {
        $request = new ReadRequest('Users');
        $request->addFilterFromArray([
            ['name', 'eq', 'John'],
            ['active', 'eq', '1'],
        ]);

        $filters = $request->getFilters();
        $this->assertEquals('name', $filters['filters'][0]['field']);
        $this->assertEquals('eq', $filters['filters'][0]['operator']);
        $this->assertEquals('John', $filters['filters'][0]['value']);
    }

    public function testAddFilterFromArrayWithOrLogic(): void
    {
        $request = new ReadRequest('Users');
        $request->addFilterFromArray([
            'logic' => 'or',
            'filters' => [
                ['field' => 'name', 'operator' => 'eq', 'value' => 'John'],
                ['field' => 'name', 'operator' => 'eq', 'value' => 'Jane'],
            ],
        ]);

        $filters = $request->getFilters();
        $this->assertEquals('or', $filters['logic']);
    }

    public function testAddSort(): void
    {
        $request = new ReadRequest('Users');
        $result = $request->addSort('created', 'desc');

        $this->assertSame($request, $result);

        $sorts = $request->getSorts();
        $this->assertCount(1, $sorts);
        $this->assertEquals(['field' => 'created', 'dir' => 'desc'], $sorts[0]);
    }

    public function testAddMultipleSorts(): void
    {
        $request = new ReadRequest('Users');
        $request->addSort('created', 'desc');
        $request->addSort('name', 'asc');

        $sorts = $request->getSorts();
        $this->assertCount(2, $sorts);
    }

    public function testGetSortsDefaultEmpty(): void
    {
        $request = new ReadRequest('Users');

        $this->assertEquals([], $request->getSorts());
    }

    public function testSetSkipErrorRequests(): void
    {
        $request = new ReadRequest('Users');
        $result = $request->setSkipErrorRequests(true);

        $this->assertSame($request, $result);
        $this->assertTrue($request->isSkipErrorRequests());
    }

    public function testIsSkipErrorRequestsDefaultFalse(): void
    {
        $request = new ReadRequest('Users');

        $this->assertFalse($request->isSkipErrorRequests());
    }

    public function testFluentInterface(): void
    {
        $request = new ReadRequest('Users');

        $result = $request
            ->setRequestType(ReadRequest::TYPE_MULTIPLE)
            ->setTake(50)
            ->setSkip(10)
            ->addFilter('active', 'eq', '1')
            ->addSort('created', 'desc')
            ->setFields(['name', 'email']);

        $this->assertSame($request, $result);
        $this->assertEquals(50, $request->getTake());
        $this->assertEquals(10, $request->getSkip());
        $this->assertCount(1, $request->getFilters()['filters']);
        $this->assertCount(1, $request->getSorts());
        $this->assertEquals(['name', 'email'], $request->getFields());
    }
}
