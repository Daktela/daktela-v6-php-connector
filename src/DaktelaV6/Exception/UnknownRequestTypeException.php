<?php

namespace Daktela\DaktelaV6\Exception;

class UnknownRequestTypeException extends RequestException
{
    public function __construct()
    {
        parent::__construct('Unknown request type', 500);
    }
}
