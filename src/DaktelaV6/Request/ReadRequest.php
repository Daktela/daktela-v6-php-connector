<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Request;

/**
 * Class ReadRequest represents the Read (GET) request used for obtaining entities
 * from Daktela V6 API. The request can be of different types, where each type
 * defines what form of data should be extracted from the model.
 *
 * The following example shows how to read a single entity:
 * ```php
 * $request = RequestFactory::buildReadRequest("CampaignsRecords")
 *     ->setRequestType(ReadRequest::TYPE_SINGLE)
 *     ->setObjectName("records_5fa299a48ab72834012563");
 * $response = $client->execute($request);
 * ```
 *
 * The following example shows how to read multiple entities:
 * ```php
 * $request = RequestFactory::buildReadRequest("CampaignsRecords")
 *     ->setRequestType(ReadRequest::TYPE_MULTIPLE)
 *     ->addFilter("created", "gte", "2020-11-01 00:00:00");
 * $response = $client->execute($request);
 * ```
 *
 * @package Daktela\DaktelaV6\Request
 */
class ReadRequest extends ARequest
{
    /** @var int Constant defining the request should return multiple entities */
    public const TYPE_MULTIPLE = 0;
    /** @var int Constant defining the request should return single entity */
    public const TYPE_SINGLE = 1;
    /** @var int Constant defining the request should return all entities regardless of pagination */
    public const TYPE_ALL = 2;
    /** @var array Variable containing currently used filters */
    private $filters = [];
    /** @var array Variable containing currently used sort conditions */
    private $sort = [];
    /** @var int Variable containing current take limit of the request */
    private $take = 100;
    /** @var int Variable containing current skip offset of the request */
    private $skip = 0;
    /** @var bool Variable determining whether error requests should be skipped when using the TYPE_ALL request type */
    private $skipErrorRequests = false;
    /** @var string|null Variable containing the object to be returned when using the TYPE_SINGLE request type */
    private $objectName = null;
    /** @var int Variable containing current type of the request */
    private $requestType = self::TYPE_MULTIPLE;
    /** @var string|null Variable containing the relation of the object to be returned when using the TYPE_MULTIPLE request type */
    private $relation = null;

    /**
     * Adds the filter to the current request type.
     * @param string $field name of the field
     * @param string $operator operator of the filter
     * @param string $value value used for filtering
     * @return $this current instance of the request to be used as builder pattern
     */
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

    /**
     * Adds the filter to the current request type when using multiple filters at once.
     * @param array $filters array containing all filters to be added to the current filter build
     * @return $this current instance of the request to be used as builder pattern
     */
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

    /**
     * Adds the sorting options to the request.
     * @param string $field field used for sorting
     * @param string $dir direction of the sorting
     * @return $this current instance of the request to be used as builder pattern
     */
    public function addSort(string $field, string $dir): self
    {
        $this->sort[] = ['field' => $field, 'dir' => $dir];
        return $this;
    }

    /**
     * Returns current sorting setup.
     * @return array current sorting setup
     */
    public function getSorts(): array
    {
        return $this->sort;
    }

    /**
     * Returns current filters setup.
     * @return array current filters setup
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * Sets the take limit of the current request.
     * @param int $take take limit of the current request
     * @return $this current instance of the request to be used as builder pattern
     */
    public function setTake(int $take): self
    {
        $this->take = $take;
        return $this;
    }

    /**
     * Gets the current set take limit of the request.
     * @return int current take limit of the request
     */
    public function getTake(): int
    {
        return $this->take;
    }

    /**
     * Sets the skip offset of the current request.
     * @param int $skip skip offset of the current request
     * @return $this current instance of the request to be used as builder pattern
     */
    public function setSkip(int $skip): self
    {
        $this->skip = $skip;
        return $this;
    }

    /**
     * Gets the skip offset of the current request.
     * @return int current skip offset of the request
     */
    public function getSkip(): int
    {
        return $this->skip;
    }

    /**
     * Sets if the error requests shall be skipped when using the TYPE_ALL request type.
     * @param bool $skipErrorRequests flag if the error requests shall be skipped
     * @return $this current instance of the request to be used as builder pattern
     */
    public function setSkipErrorRequests(bool $skipErrorRequests): self
    {
        $this->skipErrorRequests = $skipErrorRequests;
        return $this;
    }

    /**
     * Returns if the error requests shall be skipped when using the TYPE_ALL request type.
     * @return bool flag if the error requests shall be skipped
     */
    public function isSkipErrorRequests(): bool
    {
        return $this->skipErrorRequests;
    }

    /**
     * Method normalizing the filter array structure.
     * @param array $filters unstructured filter array
     * @return array structured filter array
     */
    private function reformatFilterArray(array &$filters): array
    {
        if (!isset($filters['filters'])) {
            $filters['filters'] = $filters;
        }

        foreach ($filters['filters'] as $key => &$filter) {
            if (
                is_array($filter)
                && count($filter) == 3
                && array_key_exists(0, $filter) && array_key_exists(1, $filter) && array_key_exists(2, $filter)
                && is_string($filter[0]) && is_string($filter[1]) && is_string($filter[2])
            ) {
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

    /**
     * Method for setting the object name to be read.
     * @param string $objectName unique name of the object that should be read
     * @return $this current instance of the request to be used as builder pattern
     */
    public function setObjectName(string $objectName): self
    {
        $this->objectName = $objectName;
        return $this;
    }

    /**
     * Method for obtaining the object name to be read.
     * @return string unique name of the object that is supposed to be read
     */
    public function getObjectName(): string
    {
        return $this->objectName;
    }

    /**
     * Method for setting the relation to be read.
     * @param string $relation name of the relation to be read
     * @return $this current instance of the request to be used as builder pattern
     */
    public function setRelation(string $relation): self
    {
        $this->relation = $relation;
        return $this;
    }

    /**
     * Method for obtaining the relation to be read on specific object.
     * @return string name of the relation to be read
     */
    public function getRelation(): string
    {
        return $this->relation;
    }

    /**
     * Sets the read request type based on the constants of the ReadRequest class.
     * @param int $requestType type of the read request
     * @return $this current instance of the request to be used as builder pattern
     */
    public function setRequestType(int $requestType): self
    {
        $this->requestType = $requestType;
        return $this;
    }

    /**
     * Gets the read request type based on the constanst of the ReadRequest class.
     * @return int type of the read request
     */
    public function getRequestType(): int
    {
        return $this->requestType;
    }
}
