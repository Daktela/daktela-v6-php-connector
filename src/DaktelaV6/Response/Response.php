<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Response;

class Response
{
    private $data;
    private $total;
    private $errors;
    private $httpStatus;

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
}
