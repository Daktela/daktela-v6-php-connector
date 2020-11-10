<?php

namespace DaktelaV6\Request;

use Daktela\DaktelaV6\Client;
use Daktela\DaktelaV6\Request\CreateRequest;
use Daktela\DaktelaV6\Request\DeleteRequest;
use Daktela\DaktelaV6\Request\ReadRequest;
use Daktela\DaktelaV6\Request\UpdateRequest;
use PHPUnit\Framework\SkippedTestSuiteError;
use PHPUnit\Framework\TestCase;

class ApiRequestTest extends TestCase
{
    private $hash;
    private $url;
    private $accessToken;

    public function setUp(): void
    {
        parent::setUp();

        $this->hash = md5(uniqid());
        $this->url = getenv("INSTANCE");
        $this->accessToken = getenv("ACCESS_TOKEN");

        if (is_null($this->url) || empty($this->url) || is_null($this->accessToken) || empty($this->accessToken)) {
            throw new SkippedTestSuiteError('Missing URL or Access token in phpunit.xml');
        }
    }

    public function testCreateRequest()
    {
        $client = new Client($this->url, $this->accessToken);
        $request = new CreateRequest("CampaignsRecords");

        $request->addStringAttribute("name", "test_create_" . $this->hash);
        $request->addStringAttribute("number", "+420111222333");
        $request->addAttributes(["action" => 5, "queue" => 311831447]);
        $response = $client->execute($request);
        self::assertNotNull($response->getData());
        self::assertEquals(201, $response->getHttpStatus());
        self::assertObjectHasAttribute('name', $response->getData());
        self::assertEquals("test_create_" . $this->hash, $response->getData()->name);
        self::assertEmpty($response->getErrors());
        self::assertEquals(1, $response->getTotal());
        self::assertEquals("+420111222333", $response->getData()->number);
    }

    public function testReadMultipleRequest()
    {
        $client = new Client($this->url, $this->accessToken);
        $request = new CreateRequest("CampaignsRecords");

        $request->addStringAttribute("name", "test_read1_" . $this->hash);
        $request->addStringAttribute("number", "+420111222333");
        $request->addAttributes(["action" => 5, "queue" => 311831447]);
        $response = $client->execute($request);
        self::assertEquals(201, $response->getHttpStatus());

        $requestRead = new ReadRequest("CampaignsRecords");
        $requestRead->addFilter("number", "eq", "+420111222333");
        $requestRead->addFilter("name", "eq", "test_read1_" . $this->hash);
        $responseRead = $client->execute($requestRead);
        self::assertNotNull($responseRead->getData());
        self::assertNotEmpty($responseRead->getData());
        self::assertEmpty($responseRead->getErrors());
        self::assertEquals(200, $responseRead->getHttpStatus());
        self::assertGreaterThan(0, $responseRead->getData());
        self::assertArrayHasKey(0, $responseRead->getData());
        self::assertObjectHasAttribute("name", $responseRead->getData()[0]);
        self::assertEquals("test_read1_" . $this->hash, $responseRead->getData()[0]->name);
    }

    public function testReadSingleRequest()
    {
        $client = new Client($this->url, $this->accessToken);
        $request = new CreateRequest("CampaignsRecords");

        $request->addStringAttribute("name", "test_read2_" . $this->hash);
        $request->addStringAttribute("number", "+420111222333");
        $request->addAttributes(["action" => 5, "queue" => 311831447]);
        $response = $client->execute($request);
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
        self::assertObjectHasAttribute("name", $responseRead->getData());
        self::assertEquals("test_read2_" . $this->hash, $responseRead->getData()->name);
    }

    public function testUpdateRequest()
    {
        $client = new Client($this->url, $this->accessToken);
        $request = new CreateRequest("CampaignsRecords");
        $request->addStringAttribute("name", "test_update_" . $this->hash)
            ->addStringAttribute("number", "+420111222333")
            ->addAttributes(["action" => 5, "queue" => 311831447]);
        $response = $client->execute($request);
        self::assertEquals(201, $response->getHttpStatus());

        $request = new UpdateRequest("CampaignsRecords");
        $request->setObjectName("test_update_" . $this->hash)
            ->addStringAttribute("number", "+420333222111")
            ->addAttributes(["action" => 0]);
        $response = $client->execute($request);
        self::assertNotNull($response->getData());
        self::assertEquals(200, $response->getHttpStatus());
        self::assertObjectHasAttribute('name', $response->getData());
        self::assertEquals("test_update_" . $this->hash, $response->getData()->name);
        self::assertEmpty($response->getErrors());
        self::assertEquals(1, $response->getTotal());
        self::assertEquals("+420333222111", $response->getData()->number);
    }

    public function testDeleteRequest()
    {
        $client = new Client($this->url, $this->accessToken);
        $request = new CreateRequest("CampaignsRecords");
        $request->addStringAttribute("name", "test_delete_" . $this->hash)
            ->addStringAttribute("number", "+420111222333")
            ->addAttributes(["action" => 5, "queue" => 311831447]);
        $response = $client->execute($request);
        self::assertEquals(201, $response->getHttpStatus());

        $request = new DeleteRequest("CampaignsRecords");
        $request->setObjectName("test_delete_" . $this->hash);
        $response = $client->execute($request);
        self::assertNull($response->getData());
        self::assertEquals(204, $response->getHttpStatus());
        self::assertEmpty($response->getErrors());
    }
}
