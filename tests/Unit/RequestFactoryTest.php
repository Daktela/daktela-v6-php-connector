<?php

declare(strict_types=1);

namespace Daktela\Tests\Unit;

use Daktela\DaktelaV6\Request\CreateRequest;
use Daktela\DaktelaV6\Request\DeleteRequest;
use Daktela\DaktelaV6\Request\ReadRequest;
use Daktela\DaktelaV6\Request\UpdateRequest;
use Daktela\DaktelaV6\RequestFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RequestFactoryTest extends TestCase
{
    public function testBuildReadRequest(): void
    {
        $request = RequestFactory::buildReadRequest('Users');

        $this->assertInstanceOf(ReadRequest::class, $request);
        $this->assertEquals('Users', $request->getModel());
        $this->assertEquals(ReadRequest::TYPE_MULTIPLE, $request->getRequestType());
    }

    public function testBuildReadSingleRequest(): void
    {
        $request = RequestFactory::buildReadSingleRequest('Users', 'user_123');

        $this->assertInstanceOf(ReadRequest::class, $request);
        $this->assertEquals('Users', $request->getModel());
        $this->assertEquals(ReadRequest::TYPE_SINGLE, $request->getRequestType());
        $this->assertEquals('user_123', $request->getObjectName());
    }

    public function testBuildReadMultipleRequest(): void
    {
        $request = RequestFactory::buildReadMultipleRequest('Users');

        $this->assertInstanceOf(ReadRequest::class, $request);
        $this->assertEquals('Users', $request->getModel());
        $this->assertEquals(ReadRequest::TYPE_MULTIPLE, $request->getRequestType());
    }

    public function testBuildReadRelationRequest(): void
    {
        $request = RequestFactory::buildReadRelationRequest('Users', 'user_123', 'Activities');

        $this->assertInstanceOf(ReadRequest::class, $request);
        $this->assertEquals('Users', $request->getModel());
        $this->assertEquals(ReadRequest::TYPE_MULTIPLE, $request->getRequestType());
        $this->assertEquals('user_123', $request->getObjectName());
        $this->assertEquals('activities', $request->getRelation()); // lcfirst applied
    }

    public function testBuildCreateRequest(): void
    {
        $request = RequestFactory::buildCreateRequest('Users');

        $this->assertInstanceOf(CreateRequest::class, $request);
        $this->assertEquals('Users', $request->getModel());
    }

    public function testBuildUpdateRequest(): void
    {
        $request = RequestFactory::buildUpdateRequest('Users');

        $this->assertInstanceOf(UpdateRequest::class, $request);
        $this->assertEquals('Users', $request->getModel());
    }

    public function testBuildDeleteRequest(): void
    {
        $request = RequestFactory::buildDeleteRequest('Users');

        $this->assertInstanceOf(DeleteRequest::class, $request);
        $this->assertEquals('Users', $request->getModel());
    }

    public function testPrivateConstructor(): void
    {
        $reflection = new ReflectionClass(RequestFactory::class);
        $constructor = $reflection->getConstructor();

        $this->assertTrue($constructor->isPrivate());
    }

    public function testBuildReadRequestWithDifferentModels(): void
    {
        $models = ['Users', 'CampaignsRecords', 'Activities', 'Tickets'];

        foreach ($models as $model) {
            $request = RequestFactory::buildReadRequest($model);
            $this->assertEquals($model, $request->getModel());
        }
    }
}
