<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Contracts\EnrollmentEventPublisherInterface;
use Illuminate\Support\Facades\Log;

final class LogEnrollmentEventPublisher implements EnrollmentEventPublisherInterface
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(string $event, array $payload): void
    {
        Log::info('enrollment.event', [
            'event' => $event,
            'topic' => config("enrollment_events.topics.{$event}"),
            'payload' => $payload,
        ]);
    }
}
