<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\EnrollmentEventPublisherInterface;
use App\Services\Enrollment\KafkaEnrollmentEventPublisher;
use App\Services\Enrollment\LogEnrollmentEventPublisher;
use App\Services\Enrollment\NullEnrollmentEventPublisher;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

final class EnrollmentEventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EnrollmentEventPublisherInterface::class, function () {
            return match (config('enrollment_events.driver')) {
                'kafka' => new KafkaEnrollmentEventPublisher,
                'log' => new LogEnrollmentEventPublisher,
                'null' => new NullEnrollmentEventPublisher,
                default => throw new InvalidArgumentException(
                    'Invalid enrollment events driver: '.config('enrollment_events.driver')
                ),
            };
        });
    }
}
