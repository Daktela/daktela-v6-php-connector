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

    /**
     * @return Response
     * @throws InvalidArgumentException
     */
    public function find(): Response;

    /**
     * @param string $name
     * @return Response
     * @throws InvalidArgumentException
     */
    public function get(string $name): Response;

    /**
     * @return Response
     * @throws InvalidArgumentException
     */
    public function post(): Response;

    /**
     * @param string $name
     * @return Response
     * @throws InvalidArgumentException
     */
    public function put(string $name): Response;

    public function getPathMask(): string;

}
