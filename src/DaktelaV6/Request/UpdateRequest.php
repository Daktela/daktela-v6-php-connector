<?php
declare(strict_types=1);

namespace Daktela\DaktelaV6\Request;

class UpdateRequest extends ARequest
{
    private $attributes = [];
    private $objectName;

    public function addStringAttribute(string $key, string $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function addIntAttribute(string $key, int $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function addFloatAttribute(string $key, float $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function addDoubleAttribute(string $key, float $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function addBoolAttribute(string $key, bool $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function addArrayAttribute(string $key, array $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function addAttributes(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if (is_string($value)) {
                $this->addStringAttribute($key, $value);
            } elseif (is_int($value)) {
                $this->addIntAttribute($key, $value);
            } elseif (is_bool($value)) {
                $this->addBoolAttribute($key, $value);
            } elseif (is_array($value)) {
                $this->addArrayAttribute($key, $value);
            } elseif (is_float($value)) {
                $this->addFloatAttribute($key, $value);
            } elseif (is_double($value)) {
                $this->addDoubleAttribute($key, $value);
            }
        }
        return $this;
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

    public function getAttributes()
    {
        return $this->attributes;
    }
}