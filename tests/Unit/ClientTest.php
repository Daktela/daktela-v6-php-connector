<?php

declare(strict_types=1);

namespace Daktela\Tests\Unit;

use Daktela\DaktelaV6\Client;
use Daktela\DaktelaV6\Exception\NotFoundException;
use Daktela\DaktelaV6\Exception\UnknownRequestTypeException;
use Daktela\DaktelaV6\Http\ApiCommunicator;
use Daktela\DaktelaV6\Iterator\PaginatedIterator;
use Daktela\DaktelaV6\Request\ARequest;
use Daktela\DaktelaV6\Request\CreateRequest;
use Daktela\DaktelaV6\Request\DeleteRequest;
use Daktela\DaktelaV6\Request\ReadRequest;
use Daktela\DaktelaV6\Request\UpdateRequest;
use Daktela\DaktelaV6\Response\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    /** @return ApiCommunicator&MockObject */
    private function createCommunicator(): ApiCommunicator
    {
        return $this->createMock(ApiCommunicator::class);
    }

    private function createClient(ApiCommunicator $communicator): Client
    {
        $client = new Client('https://test-' . uniqid() . '.example.com', 'token');
        $property = new \ReflectionProperty(Client::class, 'apiCommunicator');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $property->setValue($client, $communicator);
        return $client;
    }

    private function getPrivateProperty(object $object, string $name): mixed
    {
        $property = new \ReflectionProperty($object, $name);
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        return $property->getValue($object);
    }

    public function testGetInstanceReturnsSameClientForSameCredentials(): void
    {
        $url = 'https://test-' . uniqid() . '.example.com';

        $first = Client::getInstance($url, 'token');
        $second = Client::getInstance($url, 'token');

        $this->assertSame($first, $second);
    }

    public function testGetInstanceSeparatesCredentials(): void
    {
        $url = 'https://test-' . uniqid() . '.example.com';

        $this->assertNotSame(
            Client::getInstance($url, 'token-a'),
            Client::getInstance($url, 'token-b')
        );
    }

    public function testGetApiCommunicator(): void
    {
        $communicator = $this->createCommunicator();
        $client = $this->createClient($communicator);

        $this->assertSame($communicator, $client->getApiCommunicator());
    }

    public function testExecuteReturnsManuallyCachedResponseWithoutSendingRequest(): void
    {
        $communicator = $this->createCommunicator();
        $communicator->expects($this->never())->method('sendRequest');
        $client = $this->createClient($communicator);
        $request = new ReadRequest('Users');
        $cached = new Response([['id' => 1]], 1, [], 200);
        $request->setResponse($cached);
        $request->setExecuted(true);

        $this->assertSame($cached, $client->execute($request));
    }

    public function testExecuteRejectsUnknownRequestClass(): void
    {
        $client = $this->createClient($this->createCommunicator());
        $request = new class ('Users') extends ARequest {
        };

        $this->expectException(UnknownRequestTypeException::class);
        $client->execute($request);
    }

    public function testExecuteRejectsUnknownReadType(): void
    {
        $client = $this->createClient($this->createCommunicator());
        $request = (new ReadRequest('Users'))->setRequestType(999);

        $this->expectException(UnknownRequestTypeException::class);
        $client->execute($request);
    }

    public function testExecuteCreateBuildsPostRequest(): void
    {
        $expected = new Response((object)['name' => 'Alice'], 1, [], 201);
        $communicator = $this->createCommunicator();
        $communicator->expects($this->once())
            ->method('sendRequest')
            ->with('POST', 'Users', ['trace' => 'yes'], ['name' => 'Alice'])
            ->willReturn($expected);
        $client = $this->createClient($communicator);
        $request = (new CreateRequest('Users'))
            ->addAdditionalQueryParameter('trace', 'yes')
            ->addStringAttribute('name', 'Alice');

        $this->assertSame($expected, $client->execute($request));
    }

    public function testExecuteUpdateBuildsPutRequest(): void
    {
        $expected = new Response((object)['name' => 'alice'], 1, [], 200);
        $communicator = $this->createCommunicator();
        $communicator->expects($this->once())
            ->method('sendRequest')
            ->with('PUT', 'Users/alice', ['trace' => 'yes'], ['active' => true])
            ->willReturn($expected);
        $client = $this->createClient($communicator);
        $request = (new UpdateRequest('Users'))
            ->setObjectName('alice')
            ->addAdditionalQueryParameter('trace', 'yes')
            ->addBoolAttribute('active', true);

        $this->assertSame($expected, $client->execute($request));
    }

    public function testExecuteUpdateRequiresObjectName(): void
    {
        $client = $this->createClient($this->createCommunicator());

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('No object name specified');
        $client->execute(new UpdateRequest('Users'));
    }

    public function testExecuteDeleteBuildsDeleteRequest(): void
    {
        $expected = new Response(null, 0, [], 204);
        $communicator = $this->createCommunicator();
        $communicator->expects($this->once())
            ->method('sendRequest')
            ->with('DELETE', 'Users/alice', ['trace' => 'yes'])
            ->willReturn($expected);
        $client = $this->createClient($communicator);
        $request = (new DeleteRequest('Users'))
            ->setObjectName('alice')
            ->addAdditionalQueryParameter('trace', 'yes');

        $this->assertSame($expected, $client->execute($request));
    }

    public function testExecuteDeleteRequiresObjectName(): void
    {
        $client = $this->createClient($this->createCommunicator());

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('No object name specified');
        $client->execute(new DeleteRequest('Users'));
    }

    public function testExecuteReadMultipleBuildsRelationQuery(): void
    {
        $expected = new Response([], 0, [], 200);
        $communicator = $this->createCommunicator();
        $communicator->expects($this->once())
            ->method('sendRequest')
            ->with('GET', 'Users/alice/groups', [
                'custom' => 'value',
                'skip' => 20,
                'take' => 10,
                'filter' => [
                    'filters' => [
                        ['field' => 'active', 'operator' => 'eq', 'value' => '1'],
                    ],
                    'logic' => 'and',
                ],
                'sort' => [['field' => 'name', 'dir' => 'asc']],
                'fields' => ['name', 'email'],
            ])
            ->willReturn($expected);
        $client = $this->createClient($communicator);
        $request = (new ReadRequest('Users'))
            ->setSkip(20)
            ->setTake(10)
            ->setObjectName('alice')
            ->setRelation('Groups')
            ->setFields(['name', 'email'])
            ->addFilter('active', 'eq', '1')
            ->addSort('name', 'asc')
            ->addAdditionalQueryParameter('custom', 'value');

        $this->assertSame($expected, $client->execute($request));
    }

    public function testExecuteReadSingleBuildsFieldsQuery(): void
    {
        $expected = new Response((object)['name' => 'Alice'], 1, [], 200);
        $communicator = $this->createCommunicator();
        $communicator->expects($this->once())
            ->method('sendRequest')
            ->with('GET', 'Users/alice', [
                'custom' => 'value',
                'fields' => ['name'],
            ])
            ->willReturn($expected);
        $client = $this->createClient($communicator);
        $request = (new ReadRequest('Users'))
            ->setRequestType(ReadRequest::TYPE_SINGLE)
            ->setObjectName('alice')
            ->setFields(['fields' => ['name']])
            ->addAdditionalQueryParameter('custom', 'value');

        $this->assertSame($expected, $client->execute($request));
    }

    public function testExecuteReadSingleRequiresObjectName(): void
    {
        $client = $this->createClient($this->createCommunicator());
        $request = (new ReadRequest('Users'))->setRequestType(ReadRequest::TYPE_SINGLE);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('No object name specified');
        $client->execute($request);
    }

    public function testExecuteReadAllCombinesPagesAndStopsAtTotal(): void
    {
        $responses = [
            new Response([['id' => 1], ['id' => 2]], 3, [], 200),
            new Response([['id' => 3]], 3, [], 200),
        ];
        $calls = [];
        $communicator = $this->createCommunicator();
        $communicator->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturnCallback(function (...$arguments) use (&$calls, &$responses): Response {
                $calls[] = $arguments;
                return array_shift($responses);
            });
        $client = $this->createClient($communicator);
        $request = (new ReadRequest('Users'))
            ->setRequestType(ReadRequest::TYPE_ALL)
            ->setTake(2)
            ->setFields(['id']);

        $response = $client->execute($request);

        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $response->getData());
        $this->assertSame(3, $response->getTotal());
        $this->assertSame(0, $calls[0][2]['skip']);
        $this->assertSame(2, $calls[1][2]['skip']);
        $this->assertSame(['id'], $calls[0][2]['fields']);
    }

    public function testExecuteReadAllAvoidsEmptyRequestAtExactTotal(): void
    {
        $communicator = $this->createCommunicator();
        $communicator->expects($this->once())
            ->method('sendRequest')
            ->willReturn(new Response([['id' => 1], ['id' => 2]], 2, [], 200));
        $client = $this->createClient($communicator);
        $request = (new ReadRequest('Users'))
            ->setRequestType(ReadRequest::TYPE_ALL)
            ->setTake(2);

        $response = $client->execute($request);

        $this->assertCount(2, $response->getData());
    }

    public function testExecuteReadAllBuildsRelationEndpoint(): void
    {
        $communicator = $this->createCommunicator();
        $communicator->expects($this->once())
            ->method('sendRequest')
            ->with('GET', 'Users/alice/groups', $this->callback(function (array $query): bool {
                return $query['skip'] === 0 && $query['take'] === 2;
            }))
            ->willReturn(new Response([], 0, [], 200));
        $client = $this->createClient($communicator);
        $request = (new ReadRequest('Users'))
            ->setRequestType(ReadRequest::TYPE_ALL)
            ->setTake(2)
            ->setObjectName('alice')
            ->setRelation('Groups');

        $response = $client->execute($request);

        $this->assertSame([], $response->getData());
    }

    public function testExecuteReadAllReturnsFirstErrorByDefault(): void
    {
        $error = new Response(null, 0, ['API error'], 200);
        $communicator = $this->createCommunicator();
        $communicator->expects($this->once())->method('sendRequest')->willReturn($error);
        $client = $this->createClient($communicator);
        $request = (new ReadRequest('Users'))->setRequestType(ReadRequest::TYPE_ALL);

        $this->assertSame($error, $client->execute($request));
    }

    public function testExecuteReadAllReturnsNonArrayResponseByDefault(): void
    {
        $unexpected = new Response((object)['id' => 1], 1, [], 200);
        $communicator = $this->createCommunicator();
        $communicator->expects($this->once())->method('sendRequest')->willReturn($unexpected);
        $client = $this->createClient($communicator);
        $request = (new ReadRequest('Users'))->setRequestType(ReadRequest::TYPE_ALL);

        $this->assertSame($unexpected, $client->execute($request));
    }

    public function testExecuteReadAllCanSkipMalformedErrorPage(): void
    {
        $responses = [
            new Response(null, 0, ['Temporary error'], 200),
            new Response([['id' => 1]], 1, [], 200),
        ];
        $communicator = $this->createCommunicator();
        $communicator->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturnCallback(function () use (&$responses): Response {
                return array_shift($responses);
            });
        $client = $this->createClient($communicator);
        $request = (new ReadRequest('Users'))
            ->setRequestType(ReadRequest::TYPE_ALL)
            ->setTake(1)
            ->setSkipErrorRequests(true);

        $response = $client->execute($request);

        $this->assertSame([['id' => 1]], $response->getData());
        $this->assertFalse($response->hasErrors());
    }

    public function testPingDelegatesToCommunicator(): void
    {
        $communicator = $this->createCommunicator();
        $communicator->expects($this->once())->method('ping')->willReturn(true);

        $this->assertTrue($this->createClient($communicator)->ping());
    }

    public function testHealthCheckDelegatesToCommunicator(): void
    {
        $expected = ['healthy' => true, 'latency_ms' => 1.5, 'status_code' => 200];
        $communicator = $this->createCommunicator();
        $communicator->expects($this->once())->method('healthCheck')->willReturn($expected);

        $this->assertSame($expected, $this->createClient($communicator)->healthCheck());
    }

    public function testIterateCreatesConfiguredIterator(): void
    {
        $client = $this->createClient($this->createCommunicator());
        $request = new ReadRequest('Users');

        $iterator = $client->iterate($request, 25, 50, false);

        $this->assertInstanceOf(PaginatedIterator::class, $iterator);
        $this->assertSame(25, $this->getPrivateProperty($iterator, 'pageSize'));
        $this->assertSame(50, $this->getPrivateProperty($iterator, 'maxItems'));
        $this->assertFalse($this->getPrivateProperty($iterator, 'stopOnError'));
    }
}
