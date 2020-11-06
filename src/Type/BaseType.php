<?php

declare(strict_types = 1);

namespace Daktela\Type;

use Exceptions\Data\NotFoundException;
use InvalidArgumentException;
use stdClass;

/** @author Petr Kalíšek <petr.kalisek@daktela.com> */
abstract class BaseType
{

    public const METHOD_GET = 'get';

    /**
     * @var stdClass|null
     */
    protected $data = null;

    /** @var array<string> */
    private $supportedMethods = [self::METHOD_GET];

    final public function __construct(?stdClass $data)
    {
        $this->data = $data;
    }

    public function toObject(): stdClass
    {
        return (object) $this->data;
    }

    public static function create(object $data)
    {
        if ($data instanceof self) {
            $data = $data->toObject();
        }

        return new static($data);
    }

    /**
     * @param string $methodName
     * @param mixed $arguments
     * @throws NotFoundException
     * @throws NotFoundException
     * @return mixed
     */
    //phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
    public function __call(string $methodName, $arguments)
    {
        $method = \lcfirst(\substr($methodName, 0, 3));

        if (!\in_array($method, $this->supportedMethods)) {
            throw new InvalidArgumentException(\sprintf('Method %s (%s) is not supported', $methodName, $method));
        }

        $baseName = \lcfirst(\substr($methodName, 3));
        $name = \strtolower((string) \preg_replace('/(?<!^)[A-Z]/', '_$0', $baseName));

        switch ($method) {
            case self::METHOD_GET:
                if (($this->data !== null) && \property_exists($this->data, $name)) {
                    return $this->data->{$name};
                }

                if (($this->data !== null) && \property_exists($this->data, $baseName)) {
                    return $this->data->{$baseName};
                }

                throw new NotFoundException(\sprintf("Property '%s' or '%s' not found", $baseName, $name));
        }
    }
}
