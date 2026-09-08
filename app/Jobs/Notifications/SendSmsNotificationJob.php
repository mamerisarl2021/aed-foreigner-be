<?php

declare(strict_types=1);

namespace App\Jobs\Notifications;

use App\Contracts\NotificationPublisherInterface;
use App\DataTransferObjects\SmsNotificationData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendSmsNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(
        public readonly SmsNotificationData $notification,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 120, 300];
    }

    public function handle(NotificationPublisherInterface $publisher): void
    {
        $publisher->publishSms($this->notification);
    }
}
