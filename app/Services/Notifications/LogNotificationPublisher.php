<?php

namespace App\Services\Notifications;

use App\Contracts\NotificationPublisherInterface;
use App\DataTransferObjects\EmailNotificationData;
use App\DataTransferObjects\SmsNotificationData;
use App\DataTransferObjects\WebsocketNotificationData;
use Illuminate\Support\Facades\Log;

final class LogNotificationPublisher implements NotificationPublisherInterface
{
    public function publishEmail(EmailNotificationData $notification): void
    {
        $this->log(config('notifications.topics.email'), 'email', $notification->toArray());
    }

    public function publishSms(SmsNotificationData $notification): void
    {
        $this->log(config('notifications.topics.sms'), 'sms', $notification->toArray());
    }

    public function publishWebsocket(WebsocketNotificationData $notification): void
    {
        $this->log(config('notifications.topics.websocket'), 'websocket', $notification->toArray());
    }

    private function log(string $topic, string $channel, array $payload): void
    {
        Log::info('Notification published (log driver)', [
            'driver' => 'log',
            'topic' => $topic,
            'channel' => $channel,
            'payload' => $payload,
        ]);
    }
}
