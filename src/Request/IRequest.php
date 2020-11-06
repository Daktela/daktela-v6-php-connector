<?php declare(strict_types = 1);

 namespace Daktela\Request;

use Daktela\BaseDaktela;
use Daktela\Response\Response;
use InvalidArgumentException;

/** @author Petr Kalíšek <petr.kalisek@daktela.com> */
interface IRequest
{

    public function limit(int $limit): IRequest;

    public function skip(int $limit): IRequest;

    public function take(int $take): IRequest;

    public function addSort(string $field, string $dir = BaseDaktela::DIRECTION_ASC): IRequest;

    public function addAttribute(string $key, string $value): IRequest;

    public function addAttributes(array $attributes): IRequest;

    public function setFilter(array $filter, string $logic = BaseDaktela::FILTER_LOGIC_AND): IRequest;

    /** @throwsInvalidArgumentExceptionn */
    public function find(): Response;

    /** @throwsInvalidArgumentExceptionn */
    public function get(string $name): Response;

    public function getPathMask(): string;

}
