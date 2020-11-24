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
     * @return mixed
     */
    public function getTotal()
    {
        return $this->total;
    }

    /**
     * @return mixed
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * @return mixed
     */
    public function getHttpStatus()
    {
        return $this->httpStatus;
    }
}
