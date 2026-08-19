<?php

declare(strict_types=1);

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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function log(string $topic, string $channel, array $payload): void
    {
        if (config('notifications.redact_log_secrets')) {
            $payload = $this->redactSecrets($payload);
        }

        Log::info('Notification published (log driver)', [
            'driver' => 'log',
            'topic' => $topic,
            'channel' => $channel,
            'payload' => $payload,
        ]);
    }

    /**
     * Mask OTP codes, passwords and tokens so secrets never land in log files.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactSecrets(array $payload): array
    {
        $sensitiveKeys = ['otp', 'password', 'pin', 'token', 'access_token'];

        array_walk_recursive($payload, function (&$value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $value = '[REDACTED]';
            }
        });

        if (isset($payload['subject']) && is_string($payload['subject'])) {
            $payload['subject'] = preg_replace('/\b\d{4,8}\b/', '[REDACTED]', $payload['subject']);
        }

        return $payload;
    }
}
