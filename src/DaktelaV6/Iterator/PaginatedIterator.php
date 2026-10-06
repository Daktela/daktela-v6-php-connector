<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Iterator;

use Daktela\DaktelaV6\Client;
use Daktela\DaktelaV6\Request\ReadRequest;
use Daktela\DaktelaV6\Response\Response;
use Generator;
use IteratorAggregate;

/**
 * Memory-efficient iterator for paginating through large datasets.
 * Implements IteratorAggregate to support foreach loops.
 *
 * Example usage:
 * ```php
 * $request = RequestFactory::buildReadRequest("Users")
 *     ->addFilter("active", "eq", "1");
 *
 * foreach ($client->iterate($request) as $user) {
 *     echo $user->name;
 * }
 * ```
 *
 * @implements IteratorAggregate<int, mixed>
 * @package Daktela\DaktelaV6\Iterator
 */
class PaginatedIterator implements IteratorAggregate
{
    private Client $client;
    private ReadRequest $baseRequest;
    private int $pageSize;
    private ?int $maxItems;
    private bool $stopOnError;
    private ?Response $errorResponse = null;

    /**
     * @param Client $client The Daktela client
     * @param ReadRequest $baseRequest The base request to paginate (will be cloned)
     * @param int $pageSize Number of items per page
     * @param int|null $maxItems Maximum items to return (null for unlimited)
     * @param bool $stopOnError Whether to stop on first error. When false, error pages are skipped
     *     only while the API-reported total shows that more records follow.
     */
    public function __construct(
        Client $client,
        ReadRequest $baseRequest,
        int $pageSize = 100,
        ?int $maxItems = null,
        bool $stopOnError = true
    ) {
        $this->client = $client;
        $this->baseRequest = clone $baseRequest;
        $this->pageSize = $pageSize;
        $this->maxItems = $maxItems;
        $this->stopOnError = $stopOnError;

        // Ensure request type is set to multiple for pagination
        $this->baseRequest->setRequestType(ReadRequest::TYPE_MULTIPLE);
    }

    /**
     * Returns a generator that yields individual items from paginated responses.
     *
     * @return Generator<int, mixed>
     */
    public function getIterator(): Generator
    {
        $offset = $this->baseRequest->getSkip();
        $itemCount = 0;
        $this->errorResponse = null;

        if ($this->maxItems !== null && $this->maxItems <= 0) {
            return;
        }

        while (true) {
            // Clone to avoid modifying the base request
            $request = clone $this->baseRequest;
            $request->setSkip($offset);
            $request->setTake($this->pageSize);

            $response = $this->client->execute($request);

            if ($response->hasErrors()) {
                if (!$this->canSkipErrorPage($response, $offset)) {
                    return;
                }
                $offset += $this->pageSize;
                continue;
            }

            $data = $response->getData();
            if (!is_array($data) || count($data) === 0) {
                return; // No more data
            }

            foreach ($data as $item) {
                yield $itemCount => $item;
                $itemCount++;

                // Check max items limit
                if ($this->maxItems !== null && $itemCount >= $this->maxItems) {
                    return;
                }
            }

            if ($this->isLastPage($response, $offset, count($data))) {
                return;
            }

            $offset += $this->pageSize;
        }
    }

    /**
     * Returns a generator that yields Response objects for each page.
     * Useful when you need access to total count and other response metadata.
     *
     * @return Generator<int, Response>
     */
    public function pages(): Generator
    {
        $offset = $this->baseRequest->getSkip();
        $pageNumber = 0;
        $this->errorResponse = null;

        while (true) {
            $request = clone $this->baseRequest;
            $request->setSkip($offset);
            $request->setTake($this->pageSize);

            $response = $this->client->execute($request);

            yield $pageNumber => $response;
            $pageNumber++;

            if ($response->hasErrors()) {
                if (!$this->canSkipErrorPage($response, $offset)) {
                    return;
                }
                $offset += $this->pageSize;
                continue;
            }

            $data = $response->getData();
            if (!is_array($data)
                || $this->isLastPage($response, $offset, count($data))
            ) {
                return;
            }

            $offset += $this->pageSize;
        }
    }

    /**
     * Decide whether iteration continues after an error page. The error response is recorded
     * when iteration stops, so that callers can tell an error from the end of the data.
     * Without a positive total there is no way to know whether more records follow, so an
     * error page then always ends the iteration instead of requesting pages indefinitely.
     */
    private function canSkipErrorPage(Response $response, int $offset): bool
    {
        $total = $response->getTotal();
        if (!$this->stopOnError && $total > 0 && $offset + $this->pageSize < $total) {
            return true;
        }

        $this->errorResponse = $response;

        return false;
    }

    /**
     * Whether the last iteration ended because of an error page rather than the end of the data.
     *
     * @return bool
     */
    public function hasStoppedOnError(): bool
    {
        return $this->errorResponse !== null;
    }

    /**
     * The error response that ended the last iteration, or null when it ended normally.
     *
     * @return Response|null
     */
    public function getErrorResponse(): ?Response
    {
        return $this->errorResponse;
    }

    /**
     * Determine whether a page is the final one using both its size and the
     * API's total count. The total check avoids an unnecessary empty request
     * when the result count is an exact multiple of the page size.
     */
    private function isLastPage(Response $response, int $offset, int $itemCount): bool
    {
        if ($itemCount < $this->pageSize) {
            return true;
        }

        $total = $response->getTotal();
        return $total > 0 && ($offset + $itemCount) >= $total;
    }

    /**
     * Collect all items into an array.
     * Warning: This defeats the memory-efficiency purpose for very large datasets.
     *
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return iterator_to_array($this->getIterator(), false);
    }

    /**
     * Count all items (requires iterating through all pages).
     *
     * @return int
     */
    public function count(): int
    {
        $count = 0;
        foreach ($this->getIterator() as $_) {
            $count++;
        }
        return $count;
    }

    /**
     * Get the first item or null if none.
     *
     * @return mixed|null
     */
    public function first(): mixed
    {
        foreach ($this->getIterator() as $item) {
            return $item;
        }
        return null;
    }

    /**
     * Check if there are any items.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->first() === null;
    }

    /**
     * Apply a callback to each item.
     *
     * @param callable $callback Function to call with each item
     * @return void
     */
    public function each(callable $callback): void
    {
        foreach ($this->getIterator() as $index => $item) {
            $callback($item, $index);
        }
    }

    /**
     * Filter items using a callback.
     *
     * @param callable $callback Filter function returning bool
     * @return Generator<int, mixed>
     */
    public function filter(callable $callback): Generator
    {
        $index = 0;
        foreach ($this->getIterator() as $item) {
            if ($callback($item)) {
                yield $index => $item;
                $index++;
            }
        }
    }

    /**
     * Map items using a callback.
     *
     * @param callable $callback Transform function
     * @return Generator<int, mixed>
     */
    public function map(callable $callback): Generator
    {
        foreach ($this->getIterator() as $index => $item) {
            yield $index => $callback($item);
        }
    }
}
