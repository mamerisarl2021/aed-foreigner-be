<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class StaffKeycloakAdminException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 502,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function status(): int
    {
        return $this->httpStatus;
    }
}
