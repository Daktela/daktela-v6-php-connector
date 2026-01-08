<?php

declare(strict_types=1);

namespace Daktela\Tests\Unit\Iterator;

use Daktela\DaktelaV6\Client;
use Daktela\DaktelaV6\Http\ApiCommunicator;
use Daktela\DaktelaV6\Iterator\PaginatedIterator;
use Daktela\DaktelaV6\Request\ReadRequest;
use Daktela\DaktelaV6\Response\Response;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\TestCase;

class PaginatedIteratorTest extends TestCase
{
    private function createMockClient(array $responses): Client
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $httpClient = new GuzzleClient(['handler' => $handlerStack]);

        $uniqueUrl = 'https://test' . uniqid() . '.com';
        $client = new Client($uniqueUrl, 'token');
        $client->getApiCommunicator()->setHttpClient($httpClient);

        return $client;
    }

    private function createJsonResponse(array $data, int $total = null): GuzzleResponse
    {
        return new GuzzleResponse(200, [], json_encode([
            'result' => [
                'data' => $data,
                'total' => $total ?? count($data),
            ],
        ]));
    }

    public function testIteratesOverSinglePage(): void
    {
        $client = $this->createMockClient([
            $this->createJsonResponse([
                ['id' => 1, 'name' => 'User 1'],
                ['id' => 2, 'name' => 'User 2'],
            ]),
        ]);

        $request = new ReadRequest('Users');
        $iterator = new PaginatedIterator($client, $request, pageSize: 10);

        $items = [];
        foreach ($iterator as $item) {
            $items[] = $item;
        }

        $this->assertCount(2, $items);
        $this->assertEquals(1, $items[0]->id);
        $this->assertEquals(2, $items[1]->id);
    }

    public function testIteratesOverMultiplePages(): void
    {
        $client = $this->createMockClient([
            $this->createJsonResponse([
                ['id' => 1],
                ['id' => 2],
            ], 4),
            $this->createJsonResponse([
                ['id' => 3],
                ['id' => 4],
            ], 4),
        ]);

        $request = new ReadRequest('Users');
        $iterator = new PaginatedIterator($client, $request, pageSize: 2);

        $items = iterator_to_array($iterator, false);

        $this->assertCount(4, $items);
    }

    public function testStopsOnEmptyPage(): void
    {
        $client = $this->createMockClient([
            $this->createJsonResponse([['id' => 1]], 1),
            $this->createJsonResponse([], 1),
        ]);

        $request = new ReadRequest('Users');
        $iterator = new PaginatedIterator($client, $request, pageSize: 1);

        $items = iterator_to_array($iterator, false);

        $this->assertCount(1, $items);
    }

    public function testRespectsMaxItems(): void
    {
        $client = $this->createMockClient([
            $this->createJsonResponse([
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
            ], 100),
        ]);

        $request = new ReadRequest('Users');
        $iterator = new PaginatedIterator($client, $request, pageSize: 10, maxItems: 2);

        $items = iterator_to_array($iterator, false);

        $this->assertCount(2, $items);
    }

    public function testToArray(): void
    {
        $client = $this->createMockClient([
            $this->createJsonResponse([
                ['id' => 1],
                ['id' => 2],
            ]),
        ]);

        $request = new ReadRequest('Users');
        $iterator = new PaginatedIterator($client, $request, pageSize: 10);

        $array = $iterator->toArray();

        $this->assertIsArray($array);
        $this->assertCount(2, $array);
    }

    public function testCount(): void
    {
        $client = $this->createMockClient([
            $this->createJsonResponse([
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
            ]),
        ]);

        $request = new ReadRequest('Users');
        $iterator = new PaginatedIterator($client, $request, pageSize: 10);

        $this->assertEquals(3, $iterator->count());
    }

    public function testFirst(): void
    {
        $client = $this->createMockClient([
            $this->createJsonResponse([
                ['id' => 1, 'name' => 'First'],
                ['id' => 2, 'name' => 'Second'],
            ]),
        ]);

        $request = new ReadRequest('Users');
        $iterator = new PaginatedIterator($client, $request, pageSize: 10);

        $first = $iterator->first();

        $this->assertEquals(1, $first->id);
        $this->assertEquals('First', $first->name);
    }

    public function testFirstReturnsNullOnEmpty(): void
    {
        $client = $this->createMockClient([
            $this->createJsonResponse([]),
        ]);

        $request = new ReadRequest('Users');
        $iterator = new PaginatedIterator($client, $request, pageSize: 10);

        $this->assertNull($iterator->first());
    }

    public function testIsEmpty(): void
    {
        $client = $this->createMockClient([
            $this->createJsonResponse([]),
        ]);

        $request = new ReadRequest('Users');
        $iterator = new PaginatedIterator($client, $request, pageSize: 10);

        $this->assertTrue($iterator->isEmpty());
    }

    public function testIsNotEmpty(): void
    {
        $client = $this->createMockClient([
            $this->createJsonResponse([['id' => 1]]),
        ]);

        $request = new ReadRequest('Users');
        $iterator = new PaginatedIterator($client, $request, pageSize: 10);

        $this->assertFalse($iterator->isEmpty());
    }

    public function testPagesYieldsResponses(): void
    {
        $client = $this->createMockClient([
            $this->createJsonResponse([['id' => 1], ['id' => 2]], 4),
            $this->createJsonResponse([['id' => 3], ['id' => 4]], 4),
        ]);

        $request = new ReadRequest('Users');
        $iterator = new PaginatedIterator($client, $request, pageSize: 2);

        $pages = [];
        foreach ($iterator->pages() as $page) {
            $pages[] = $page;
        }

        $this->assertCount(2, $pages);
        $this->assertInstanceOf(Response::class, $pages[0]);
        $this->assertInstanceOf(Response::class, $pages[1]);
    }

    public function testEach(): void
    {
        $client = $this->createMockClient([
            $this->createJsonResponse([
                ['id' => 1],
                ['id' => 2],
            ]),
        ]);

        $request = new ReadRequest('Users');
        $iterator = new PaginatedIterator($client, $request, pageSize: 10);

        $ids = [];
        $iterator->each(function ($item) use (&$ids) {
            $ids[] = $item->id;
        });

        $this->assertEquals([1, 2], $ids);
    }

    public function testFilter(): void
    {
        $client = $this->createMockClient([
            $this->createJsonResponse([
                ['id' => 1, 'active' => true],
                ['id' => 2, 'active' => false],
                ['id' => 3, 'active' => true],
            ]),
        ]);

        $request = new ReadRequest('Users');
        $iterator = new PaginatedIterator($client, $request, pageSize: 10);

        $filtered = iterator_to_array($iterator->filter(fn($item) => $item->active), false);

        $this->assertCount(2, $filtered);
        $this->assertEquals(1, $filtered[0]->id);
        $this->assertEquals(3, $filtered[1]->id);
    }

    public function testMap(): void
    {
        $client = $this->createMockClient([
            $this->createJsonResponse([
                ['id' => 1, 'name' => 'User 1'],
                ['id' => 2, 'name' => 'User 2'],
            ]),
        ]);

        $request = new ReadRequest('Users');
        $iterator = new PaginatedIterator($client, $request, pageSize: 10);

        $names = iterator_to_array($iterator->map(fn($item) => $item->name), false);

        $this->assertEquals(['User 1', 'User 2'], $names);
    }
}
