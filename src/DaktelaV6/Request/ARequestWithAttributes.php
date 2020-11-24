<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Request;

class ARequestWithAttributes extends ARequest
{

    /** @var array attributes of the create request */
    private $attributes = [];

    /**
     * Adds a string attribute to the request.
     * @param string $key key of the attribute to be added
     * @param string $value value of attribute to be added
     * @return $this current instance of the create request to be used as builder pattern
     */
    public function addAttribute(string $key, string $value): self
    {
        return $this->addStringAttribute($key, $value);
    }

    /**
     * Adds a string attribute to the request.
     * @param string $key key of the attribute to be added
     * @param string $value value of attribute to be added
     * @return $this current instance of the create request to be used as builder pattern
     */
    public function addStringAttribute(string $key, string $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Adds an integer attribute to the request.
     * @param string $key key of the attribute to be added
     * @param int $value value of attribute to be added
     * @return $this current instance of the create request to be used as builder pattern
     */
    public function addIntAttribute(string $key, int $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Adds a float attribute to the request.
     * @param string $key key of the attribute to be added
     * @param float $value value of attribute to be added
     * @return $this current instance of the create request to be used as builder pattern
     */
    public function addFloatAttribute(string $key, float $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Adds a double attribute to the request.
     * @param string $key key of the attribute to be added
     * @param double $value value of attribute to be added
     * @return $this current instance of the create request to be used as builder pattern
     */
    public function addDoubleAttribute(string $key, float $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Adds a boolean attribute to the request.
     * @param string $key key of the attribute to be added
     * @param bool $value value of attribute to be added
     * @return $this current instance of the create request to be used as builder pattern
     */
    public function addBoolAttribute(string $key, bool $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Adds an array attribute to the request.
     * @param string $key key of the attribute to be added
     * @param array $value value of attribute to be added
     * @return $this current instance of the create request to be used as builder pattern
     */
    public function addArrayAttribute(string $key, array $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Adds all attributes from provided array to the request.
     * @param array $attributes array containing all attributes to be added
     * @return $this current instance of the create request to be used as builder pattern
     */
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

    /**
     * Returns the current set of attributes that is part of the request.
     * @return array current set of attributes to be sent as create request
     */
    public function getAttributes()
    {
        return $this->attributes;
    }
}
