<?php

declare(strict_types = 1);

namespace Daktela\Request;

use Daktela\BaseDaktela;
use Daktela\Response\Response;

/** @author Petr Kalíšek <petr.kalisek@daktela.com> */
interface IRequest
{

    public function limit(int $limit): IRequest;

    public function skip(int $limit): IRequest;

    public function take(int $take): IRequest;

    public function addSort(string $field, string $dir = BaseDaktela::DIRECTION_ASC): IRequest;

    public function setFilter(array $filter, string $logic = BaseDaktela::FILTER_LOGIC_AND): IRequest;

    //public function getResult(): Response;

    public function find(): Response;

    public function get(string $name): Response;

    public function getPathMask(): string;

}
