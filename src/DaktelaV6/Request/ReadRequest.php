<?php
declare(strict_types=1);

namespace Daktela\DaktelaV6\Request;

use Daktela\DaktelaV6\Http\ApiCommunicator;
use Daktela\DaktelaV6\Response\Response;

class ReadRequest extends ARequest
{
    const TYPE_MULTIPLE = 0;
    const TYPE_SINGLE = 1;
    const TYPE_ALL = 2;
    private $filters = [];
    private $sort = [];
    private $take = 100;
    private $skip = 0;
    private $returnAllRecords = false;
    private $skipErrorRequests = false;
    private $objectName = null;
    private $requestType = self::TYPE_MULTIPLE;

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

    public function setObjectName(string $objectName): self
    {
        $this->objectName = $objectName;
        return $this;
    }

    public function getObjectName(): string
    {
        return $this->objectName;
    }

    public function setRequestType(int $requestType): self
    {
        $this->requestType = $requestType;
        return $this;
    }

    public function getRequestType(): int
    {
        return $this->requestType;
    }

}