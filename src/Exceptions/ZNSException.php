<?php

namespace CPP\ZNSPackage\Exceptions;

use Exception;

class ZNSException extends Exception
{
    public function __construct(string $message = '', int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}