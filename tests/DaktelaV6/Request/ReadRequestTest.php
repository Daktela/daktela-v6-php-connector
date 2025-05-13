<?php

namespace Daktela\DaktelaV6\Request;

use PHPUnit\Framework\SkippedTestSuiteError;
use PHPUnit\Framework\TestCase;

class ReadRequestTest extends TestCase
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

    public function testAddFilter()
    {
        $request = new ReadRequest($this->url, $this->accessToken, "Users");

        $request->addFilter("edited", "lte", "2020-11-30 23:59:59");
        $request->addFilter("action", "eq", "0");
        $filters = $request->getFilters();

        self::assertArrayHasKey("logic", $filters);
        self::assertArrayHasKey("filters", $filters);
        self::assertCount(2, $filters["filters"]);
        self::assertArrayHasKey("field", $filters["filters"][0]);
        self::assertArrayHasKey("operator", $filters["filters"][0]);
        self::assertArrayHasKey("value", $filters["filters"][0]);
        self::assertArrayHasKey("field", $filters["filters"][1]);
        self::assertArrayHasKey("operator", $filters["filters"][1]);
        self::assertArrayHasKey("value", $filters["filters"][1]);

        self::assertEquals($filters["logic"], "and");
        self::assertEquals($filters["filters"][0]["field"], "edited");
        self::assertEquals($filters["filters"][0]["operator"], "lte");
        self::assertEquals($filters["filters"][0]["value"], "2020-11-30 23:59:59");
        self::assertEquals($filters["filters"][1]["field"], "action");
        self::assertEquals($filters["filters"][1]["operator"], "eq");
        self::assertEquals($filters["filters"][1]["value"], "0");
    }

  public function testAddSingleFilterWithArrayAsValue()
  {
    $request = new ReadRequest($this->url, $this->accessToken, "Tickets");

    $request->addFilter("stage", "in", ["OPEN", "WAIT"]);
    $filters = $request->getFilters();

    self::assertArrayHasKey("logic", $filters);
    self::assertArrayHasKey("filters", $filters);
    self::assertCount(1, $filters["filters"]);
    self::assertArrayHasKey("field", $filters["filters"][0]);
    self::assertArrayHasKey("operator", $filters["filters"][0]);
    self::assertArrayHasKey("value", $filters["filters"][0]);

    self::assertEquals($filters["logic"], "and");
    self::assertEquals($filters["filters"][0]["field"], "stage");
    self::assertEquals($filters["filters"][0]["operator"], "in");
    self::assertEquals($filters["filters"][0]["value"], ["OPEN", "WAIT"]);
  }

    public function testAddFilterFromArray()
    {
        $request = new ReadRequest($this->url, $this->accessToken, "Users");

        $filters = [
            ["field" => "edited", "operator" => "lte", "value" => "2020-11-30 23:59:59"],
            ["action", "eq", "0"],
        ];

        $request->addFilterFromArray($filters);
        $filters = $request->getFilters();

        self::assertArrayHasKey("logic", $filters);
        self::assertArrayHasKey("filters", $filters);
        self::assertCount(2, $filters["filters"]);
        self::assertArrayHasKey("field", $filters["filters"][0]);
        self::assertArrayHasKey("operator", $filters["filters"][0]);
        self::assertArrayHasKey("value", $filters["filters"][0]);
        self::assertArrayHasKey("field", $filters["filters"][1]);
        self::assertArrayHasKey("operator", $filters["filters"][1]);
        self::assertArrayHasKey("value", $filters["filters"][1]);

        self::assertEquals($filters["logic"], "and");
        self::assertEquals($filters["filters"][0]["field"], "edited");
        self::assertEquals($filters["filters"][0]["operator"], "lte");
        self::assertEquals($filters["filters"][0]["value"], "2020-11-30 23:59:59");
        self::assertEquals($filters["filters"][1]["field"], "action");
        self::assertEquals($filters["filters"][1]["operator"], "eq");
        self::assertEquals($filters["filters"][1]["value"], "0");
    }

  public function testAddShortHandFilterWithArrayFromArray()
  {
    $request = new ReadRequest($this->url, $this->accessToken, "Users");

    $filters = [
      ["type", "in", ["SMS", "EMAIL", "CALL"]],
    ];

    $request->addFilterFromArray($filters);
    $filters = $request->getFilters();

    self::assertArrayHasKey("logic", $filters);
    self::assertArrayHasKey("filters", $filters);
    self::assertCount(1, $filters["filters"]);
    self::assertArrayHasKey("field", $filters["filters"][0]);
    self::assertArrayHasKey("operator", $filters["filters"][0]);
    self::assertArrayHasKey("value", $filters["filters"][0]);

    self::assertEquals($filters["logic"], "and");
    self::assertEquals($filters["filters"][0]["field"], "type");
    self::assertEquals($filters["filters"][0]["operator"], "in");
    self::assertEquals($filters["filters"][0]["value"][0], "SMS");
    self::assertEquals($filters["filters"][0]["value"][1], "EMAIL");
    self::assertEquals($filters["filters"][0]["value"][2], "CALL");
  }

    public function testAddOrFilterFromArray()
    {
        $request = new ReadRequest($this->url, $this->accessToken, "Users");

        $filters = [
            ["field" => "edited", "operator" => "lte", "value" => "2020-11-30 23:59:59"],
            ["action", "eq", "0"],
        ];

        $request->addFilterFromArray(
            [
                'logic' => 'or',
                'filters' => $filters,
            ]
        );
        $filters = $request->getFilters();

        self::assertArrayHasKey("logic", $filters);
        self::assertArrayHasKey("filters", $filters);
        self::assertCount(2, $filters["filters"]);
        self::assertArrayHasKey("field", $filters["filters"][0]);
        self::assertArrayHasKey("operator", $filters["filters"][0]);
        self::assertArrayHasKey("value", $filters["filters"][0]);
        self::assertArrayHasKey("field", $filters["filters"][1]);
        self::assertArrayHasKey("operator", $filters["filters"][1]);
        self::assertArrayHasKey("value", $filters["filters"][1]);

        self::assertEquals($filters["logic"], "or");
        self::assertEquals($filters["filters"][0]["field"], "edited");
        self::assertEquals($filters["filters"][0]["operator"], "lte");
        self::assertEquals($filters["filters"][0]["value"], "2020-11-30 23:59:59");
        self::assertEquals($filters["filters"][1]["field"], "action");
        self::assertEquals($filters["filters"][1]["operator"], "eq");
        self::assertEquals($filters["filters"][1]["value"], "0");
    }

    public function testAddCombinedFilters()
    {
        $request = new ReadRequest($this->url, $this->accessToken, "Users");

        $request->addFilter("edited", "lte", "2020-11-30 23:59:59");
        $request->addFilter("action", "eq", "0");
        $filters = [
            ["field" => "created", "operator" => "lte", "value" => "2020-11-30 23:59:59"],
            ["user", "isnull", ""],
        ];

        $request->addFilterFromArray($filters);
        $filters = $request->getFilters();

        self::assertArrayHasKey("logic", $filters);
        self::assertArrayHasKey("filters", $filters);
        self::assertCount(4, $filters["filters"]);
        self::assertArrayHasKey("field", $filters["filters"][0]);
        self::assertArrayHasKey("operator", $filters["filters"][0]);
        self::assertArrayHasKey("value", $filters["filters"][0]);
        self::assertArrayHasKey("field", $filters["filters"][1]);
        self::assertArrayHasKey("operator", $filters["filters"][1]);
        self::assertArrayHasKey("value", $filters["filters"][1]);
        self::assertArrayHasKey("field", $filters["filters"][2]);
        self::assertArrayHasKey("operator", $filters["filters"][2]);
        self::assertArrayHasKey("value", $filters["filters"][2]);
        self::assertArrayHasKey("field", $filters["filters"][3]);
        self::assertArrayHasKey("operator", $filters["filters"][3]);
        self::assertArrayHasKey("value", $filters["filters"][3]);

        self::assertEquals($filters["logic"], "and");
        self::assertEquals($filters["filters"][0]["field"], "edited");
        self::assertEquals($filters["filters"][0]["operator"], "lte");
        self::assertEquals($filters["filters"][0]["value"], "2020-11-30 23:59:59");
        self::assertEquals($filters["filters"][1]["field"], "action");
        self::assertEquals($filters["filters"][1]["operator"], "eq");
        self::assertEquals($filters["filters"][1]["value"], "0");
        self::assertEquals($filters["filters"][2]["field"], "created");
        self::assertEquals($filters["filters"][2]["operator"], "lte");
        self::assertEquals($filters["filters"][2]["value"], "2020-11-30 23:59:59");
        self::assertEquals($filters["filters"][3]["field"], "user");
        self::assertEquals($filters["filters"][3]["operator"], "isnull");
        self::assertEquals($filters["filters"][3]["value"], "");
    }
}
