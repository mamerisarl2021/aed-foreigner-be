<?php

namespace App\Exceptions;

use Exception;

class TransactionException extends Exception
{
    public function __construct($message = "Erreur en cours d'opération", $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
