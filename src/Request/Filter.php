<?php

declare(strict_types=1);

namespace Daktela\Request;

/**
 * @author Petr Kalíšek <petr.kalisek@daktela.com>
 *
 * Basic, in feature add more logic, filter cascade etc...
 */
class Filter
{

    const OPERATOR_EQ = 'eq';
    const LOGIC_OR = 'or';
    const LOGIC_AND = 'and';

    private $filter = ['logic' => 'and', 'filters' => []];

    public static function createFilter(): Filter
    {
        return new self();
    }

    public function addFilter(string $field, string $value, string $operator = self::OPERATOR_EQ): self
    {
        $this->filter['filters'][] = ['field' => $field, 'value' => $value, 'operator' => $operator];

        return $this;
    }

    public function toArray(): array
    {
        return $this->filter;
    }

}
