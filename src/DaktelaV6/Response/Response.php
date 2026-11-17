<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Response;

class Response
{
    private mixed $data;
    private int $total;
    private array $errors;
    private int $httpStatus;

    public function __construct($data, int $total, array $errors, int $httpStatus)
    {
        $this->data = $data;
        $this->total = $total;
        $this->errors = $errors;
        $this->httpStatus = $httpStatus;
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @return int response total rows
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * @return array array of errors if any
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return int HTTP status code
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Check if the response indicates success (2xx status codes).
     *
     * @return bool True if HTTP status is 2xx
     */
    public function isSuccess(): bool
    {
        return $this->httpStatus >= 200 && $this->httpStatus < 300;
    }

    /**
     * Check if the response contains any errors.
     *
     * @return bool True if errors array is not empty
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Get the first error from the errors array. The API reports errors as a list or as a map, e.g.
     * `['title' => 'required']` for a form error; the first entry is returned in both cases.
     *
     * @return mixed|null First error or null if no errors
     */
    public function getFirstError(): mixed
    {
        return $this->errors === [] ? null : $this->errors[array_key_first($this->errors)];
    }

    /**
     * Check if the response data is empty.
     *
     * @return bool True if data is null, empty array, or empty object
     */
    public function isEmpty(): bool
    {
        if ($this->data === null) {
            return true;
        }
        if (is_array($this->data)) {
            return count($this->data) === 0;
        }
        if (is_object($this->data)) {
            return (array)$this->data === [];
        }
        return false;
    }
}
