<?php

namespace App\Providers;

use App\Contracts\NotificationPublisherInterface;
use App\Services\Notifications\KafkaNotificationPublisher;
use Illuminate\Support\ServiceProvider;

final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            NotificationPublisherInterface::class,
            KafkaNotificationPublisher::class
        );
    }
}
