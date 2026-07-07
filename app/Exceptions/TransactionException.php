<?php

namespace App\Exceptions;

use Exception;

class TransactionException extends Exception
{
    // TODO: resolver implicit null marking of parameter deprecation warning
    public function __construct($message = "Erreur en cours d'opération", $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
