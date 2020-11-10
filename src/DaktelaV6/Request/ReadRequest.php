<?php
declare(strict_types=1);

namespace Daktela\DaktelaV6\Request;

use Daktela\DaktelaV6\Response\Response;

class ReadRequest extends ARequest
{
    const READ_LIMIT = 999;
    private $model;
    private $filters = [];
    private $sort = [];
    private $take = 100;
    private $skip = 0;
    private $returnAllRecords = false;
    private $skipErrorRequests = false;

    public function __construct(string $instance, string $accessToken, string $model)
    {
        parent::__construct($instance, $accessToken);
        $this->model = $model;
    }

    public function addFilter(string $field, string $operator, string $value): self
    {
        $newFilter = ['field' => $field, 'operator' => $operator, 'value' => $value];

        if (!isset($this->filters['filters'])) {
            $this->filters['filters'] = [];
        }
        if (!isset($this->filters['logic'])) {
            $this->filters['logic'] = 'and';
        }

        $this->filters['filters'][] = $newFilter;

        return $this;
    }

    public function addFilterFromArray(array $filters): self
    {
        if (!isset($filters['filters'])) {
            $filters = [
                'logic' => $filters['logic'] ?? 'and',
                'filters' => $filters
            ];
        }

        $newFilters = $this->reformatFilterArray($filters);

        if (!isset($this->filters['filters'])) {
            $this->filters['filters'] = [];
        }
        if (!isset($this->filters['logic'])) {
            $this->filters['logic'] = $filters['logic'] ?? 'and';
        }

        $this->filters['filters'] = array_merge($this->filters['filters'], $newFilters['filters']);

        return $this;
    }

    public function addSort(string $field, string $dir): self
    {
        $this->sort[] = ['field' => $field, 'dir' => $dir];
        return $this;
    }

    public function getSorts(): array
    {
        return $this->sort;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function setTake(int $take): self
    {
        $this->take = $take;
        return $this;
    }

    public function getTake(): int
    {
        return $this->take;
    }

    public function setSkip(int $skip): self
    {
        $this->skip = $skip;
        return $this;
    }

    public function getSkip(): int
    {
        return $this->skip;
    }

    public function setReturnAllRecords(bool $returnAllRecords): self
    {
        $this->returnAllRecords = $returnAllRecords;
        return $this;
    }

    public function isReturnAllRecords(): bool
    {
        return $this->returnAllRecords;
    }

    public function setSkipErrorRequests(bool $skipErrorRequests): self
    {
        $this->skipErrorRequests = $skipErrorRequests;
        return $this;
    }

    public function isSkipErrorRequests(): bool
    {
        return $this->skipErrorRequests;
    }

    private function reformatFilterArray(array &$filters): array
    {
        if (!isset($filters['filters'])) {
            $filters['filters'] = $filters;
        }

        foreach ($filters['filters'] as $key => &$filter) {
            if (is_array($filter)
                && count($filter) == 3
                && array_key_exists(0, $filter) && array_key_exists(1, $filter) && array_key_exists(2, $filter)
                && is_string($filter[0]) && is_string($filter[1]) && is_string($filter[2])) {
                $filter["field"] = $filter[0];
                $filter["operator"] = $filter[1];
                $filter["value"] = $filter[2];
                unset($filter[0]);
                unset($filter[1]);
                unset($filter[2]);
            }
        }

        return $filters;
    }

    private function getObjects(): Response
    {
        $queryParams = ['skip' => $this->skip, 'take' => $this->take, 'filter' => $this->filters, 'sort' => $this->sort];
        return $this->sendRequest("GET", $this->model, $queryParams);
    }

    private function getAllObjects(): Response
    {
        $response = new Response();
        for ($i = 0; $i < self::READ_LIMIT; $i++) {
            $queryParams = ['skip' => ($i * $this->take), 'take' => $this->take, 'filter' => $this->filters, 'sort' => $this->sort];
            $currentResponse = $this->sendRequest("GET", $this->model, $queryParams);

            if (!empty($currentResponse->getErrors()) && !$this->skipErrorRequests) {
                return $currentResponse;
            }

            $data = array_merge($response->getData(), $currentResponse->getData());
            $response = new Response($data, $currentResponse->getTotal(), $currentResponse->getErrors(), $currentResponse->getHttpStatus());

            //If returned less than take, it is the last page
            if (count($currentResponse->getData()) < $this->take) {
                break;
            }
        }
        return $response;
    }

    public function getObjectByName(string $objectName): Response
    {
        return $this->sendRequest("GET", $this->model . "/" . $objectName);
    }

    protected function executeRequest(): Response
    {
        if ($this->returnAllRecords) {
            return $this->getAllObjects();
        }
        return $this->getObjects();
    }

}