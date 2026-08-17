<?php

declare(strict_types=1);

namespace App\Jobs\Notifications;

use App\Contracts\NotificationPublisherInterface;
use App\DataTransferObjects\EmailNotificationData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendEmailNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(
        public readonly EmailNotificationData $notification,
    ) {}

    public function backoff(): array
    {
        return [60, 120, 300];
    }

    public function handle(NotificationPublisherInterface $publisher): void
    {
        $publisher->publishEmail($this->notification);
    }
}
