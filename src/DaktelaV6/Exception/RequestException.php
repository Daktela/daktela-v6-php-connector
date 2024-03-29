<?php

namespace Daktela\DaktelaV6\Exception;

use Exception;
use Throwable;

class RequestException extends Exception
{
    public function __construct($message, $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
