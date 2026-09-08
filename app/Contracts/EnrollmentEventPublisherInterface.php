<?php

declare(strict_types=1);

namespace App\Contracts;

interface EnrollmentEventPublisherInterface
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(string $event, array $payload): void;
}
