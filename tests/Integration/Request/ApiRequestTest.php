<?php

declare(strict_types=1);

namespace Daktela\Tests\Integration\Request;

use Daktela\DaktelaV6\Client;
use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\Request\CreateRequest;
use Daktela\DaktelaV6\Request\DeleteRequest;
use Daktela\DaktelaV6\Request\ReadRequest;
use Daktela\DaktelaV6\Request\UpdateRequest;
use Daktela\DaktelaV6\Response\Response;
use PHPUnit\Framework\TestCase;

/**
 * Creates, reads, updates, and deletes CampaignsRecords on a real Daktela instance set by the INSTANCE,
 * ACCESS_TOKEN, and RECORD_TYPE (a queue of campaign records) environment variables. The tests are skipped
 * when one of them is empty. Every record a test creates is deleted after the test.
 */
class ApiRequestTest extends TestCase
{
    private string $hash;
    private string $url;
    private string $accessToken;
    private string $recordType;
    /** @var string[] names of the records created by the current test */
    private array $createdRecords = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->hash = md5(uniqid());
        $this->url = (string)getenv("INSTANCE");
        $this->accessToken = (string)getenv("ACCESS_TOKEN");
        $this->recordType = (string)getenv("RECORD_TYPE");
        $this->createdRecords = [];

        if ($this->url === '' || $this->accessToken === '' || $this->recordType === '') {
            $this->markTestSkipped('Set INSTANCE, ACCESS_TOKEN and RECORD_TYPE to run the integration tests');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRecords as $name) {
            try {
                $request = new DeleteRequest("CampaignsRecords");
                $request->setObjectName($name);
                (new Client($this->url, $this->accessToken))->execute($request);
            } catch (RequestException $ex) {
                // Already deleted by the test, or the instance is unreachable: nothing more to clean up
            }
        }
        $this->createdRecords = [];

        parent::tearDown();
    }

    /**
     * Creates a campaign record that tearDown() deletes.
     */
    private function createRecord(Client $client, string $name): Response
    {
        $request = new CreateRequest("CampaignsRecords");
        $request->addStringAttribute("name", $name)
            ->addStringAttribute("number", "+420111222333")
            ->addAttributes(["action" => 5, "queue" => $this->recordType]);
        $this->createdRecords[] = $name;

        return $client->execute($request);
    }

    public function testCreateRequest()
    {
        $client = new Client($this->url, $this->accessToken);
        $response = $this->createRecord($client, "test_create_" . $this->hash);
        self::assertNotNull($response->getData());
        self::assertEquals(201, $response->getHttpStatus());
        self::assertTrue(property_exists($response->getData(), 'name'));
        self::assertEquals("test_create_" . $this->hash, $response->getData()->name);
        self::assertEmpty($response->getErrors());
        self::assertEquals(1, $response->getTotal());
        self::assertEquals("+420111222333", $response->getData()->number);
    }

    public function testReadMultipleRequest()
    {
        $client = new Client($this->url, $this->accessToken);
        $response = $this->createRecord($client, "test_read1_" . $this->hash);
        self::assertEquals(201, $response->getHttpStatus());

        $requestRead = new ReadRequest("CampaignsRecords");
        $requestRead->addFilter("number", "eq", "+420111222333");
        $requestRead->addFilter("name", "eq", "test_read1_" . $this->hash);
        $responseRead = $client->execute($requestRead);
        self::assertNotNull($responseRead->getData());
        self::assertNotEmpty($responseRead->getData());
        self::assertEmpty($responseRead->getErrors());
        self::assertEquals(200, $responseRead->getHttpStatus());
        self::assertGreaterThan(0, count($responseRead->getData()));
        self::assertArrayHasKey(0, $responseRead->getData());
        self::assertTrue(property_exists($responseRead->getData()[0], 'name'));
        self::assertEquals("test_read1_" . $this->hash, $responseRead->getData()[0]->name);
    }

    public function testReadAllRequest()
    {
        $client = new Client($this->url, $this->accessToken);
        $response = $this->createRecord($client, "test_read3_" . $this->hash);
        self::assertEquals(201, $response->getHttpStatus());

        $requestRead = new ReadRequest("CampaignsRecords");
        $requestRead->setRequestType(ReadRequest::TYPE_ALL);
        $requestRead->addFilter("number", "eq", "+420111222333");
        $requestRead->addFilter("name", "eq", "test_read3_" . $this->hash);
        $responseRead = $client->execute($requestRead);
        self::assertNotNull($responseRead->getData());
        self::assertNotEmpty($responseRead->getData());
        self::assertEmpty($responseRead->getErrors());
        self::assertEquals(200, $responseRead->getHttpStatus());
        self::assertGreaterThan(0, count($responseRead->getData()));
        self::assertArrayHasKey(0, $responseRead->getData());
        self::assertTrue(property_exists($responseRead->getData()[0], 'name'));
        self::assertEquals("test_read3_" . $this->hash, $responseRead->getData()[0]->name);
    }

    public function testReadSingleRequest()
    {
        $client = new Client($this->url, $this->accessToken);
        $response = $this->createRecord($client, "test_read2_" . $this->hash);
        self::assertEquals(201, $response->getHttpStatus());

        $requestRead = new ReadRequest("CampaignsRecords");
        $requestRead->setRequestType(ReadRequest::TYPE_SINGLE);
        $requestRead->setObjectName("test_read2_" . $this->hash);
        $responseRead = $client->execute($requestRead);
        self::assertNotNull($responseRead->getData());
        self::assertNotEmpty($responseRead->getData());
        self::assertEmpty($responseRead->getErrors());
        self::assertEquals(200, $responseRead->getHttpStatus());
        self::assertEquals(1, $responseRead->getTotal());
        self::assertTrue(property_exists($responseRead->getData(), 'name'));
        self::assertEquals("test_read2_" . $this->hash, $responseRead->getData()->name);
    }

    public function testUpdateRequest()
    {
        $client = new Client($this->url, $this->accessToken);
        $response = $this->createRecord($client, "test_update_" . $this->hash);
        self::assertEquals(201, $response->getHttpStatus());

        $request = new UpdateRequest("CampaignsRecords");
        $request->setObjectName("test_update_" . $this->hash)
            ->addStringAttribute("number", "+420333222111")
            ->addAttributes(["action" => 0]);
        $response = $client->execute($request);
        self::assertNotNull($response->getData());
        self::assertEquals(200, $response->getHttpStatus());
        self::assertTrue(property_exists($response->getData(), 'name'));
        self::assertEquals("test_update_" . $this->hash, $response->getData()->name);
        self::assertEmpty($response->getErrors());
        self::assertEquals(1, $response->getTotal());
        self::assertEquals("+420333222111", $response->getData()->number);
    }

    public function testDeleteRequest()
    {
        $client = new Client($this->url, $this->accessToken);
        $response = $this->createRecord($client, "test_delete_" . $this->hash);
        self::assertEquals(201, $response->getHttpStatus());

        $request = new DeleteRequest("CampaignsRecords");
        $request->setObjectName("test_delete_" . $this->hash);
        $response = $client->execute($request);
        self::assertNull($response->getData());
        self::assertEquals(204, $response->getHttpStatus());
        self::assertEmpty($response->getErrors());
    }

    public function testReadRequestWithSingleFilterWithArrayAsValue()
    {
        $client = new Client($this->url, $this->accessToken);
        $response = $this->createRecord($client, "test_single_filter_array_" . $this->hash);
        self::assertEquals(201, $response->getHttpStatus());

        $requestRead = new ReadRequest("CampaignsRecords");
        $requestRead->setRequestType(ReadRequest::TYPE_ALL);
        $requestRead->addFilter("action", "in", [3, 4, 5]);
        $requestRead->addFilter("name", "eq", "test_single_filter_array_" . $this->hash);
        $responseRead = $client->execute($requestRead);
        self::assertEquals(200, $responseRead->getHttpStatus());
        self::assertNotNull($responseRead->getData());
        self::assertNotEmpty($responseRead->getData());
        self::assertEmpty($responseRead->getErrors());
        self::assertTrue($responseRead->getTotal() > 0);
        self::assertTrue(property_exists($responseRead->getData()[0], 'name'));
        self::assertEquals("test_single_filter_array_" . $this->hash, $responseRead->getData()[0]->name);
    }
}
