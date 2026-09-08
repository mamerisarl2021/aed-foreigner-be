<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Contracts\EnrollmentEventPublisherInterface;

final class NullEnrollmentEventPublisher implements EnrollmentEventPublisherInterface
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(string $event, array $payload): void {}
}
