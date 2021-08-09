<?php

namespace Daktela\DaktelaV6\Exception;

class NotFoundException extends RequestException
{
    public function __construct($message)
    {
        parent::__construct($message, 404);
    }
}
