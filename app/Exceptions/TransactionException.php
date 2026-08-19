<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Throwable;

class TransactionException extends Exception
{
    public function __construct(string $message = "Erreur en cours d'opération", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
