<?php

namespace App\Providers;

use App\Contracts\NotificationPublisherInterface;
use App\Services\Notifications\KafkaNotificationPublisher;
use App\Services\Notifications\LogNotificationPublisher;
use App\Services\Notifications\NullNotificationPublisher;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NotificationPublisherInterface::class, function () {
            return match (config('notifications.driver')) {
                'kafka' => new KafkaNotificationPublisher,
                'log' => new LogNotificationPublisher,
                'null' => new NullNotificationPublisher,
                default => throw new InvalidArgumentException(
                    'Invalid notification driver: '.config('notifications.driver')
                ),
            };
        });
    }
}
