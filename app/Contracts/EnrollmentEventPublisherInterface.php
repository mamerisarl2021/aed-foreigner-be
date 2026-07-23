<?php

declare(strict_types=1);

namespace App\Contracts;

interface EnrollmentEventPublisherInterface
{
    public function publish(string $event, array $payload): void;
}
