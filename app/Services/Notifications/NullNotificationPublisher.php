<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Contracts\NotificationPublisherInterface;
use App\DataTransferObjects\EmailNotificationData;
use App\DataTransferObjects\SmsNotificationData;
use App\DataTransferObjects\WebsocketNotificationData;

final class NullNotificationPublisher implements NotificationPublisherInterface
{
    public function publishEmail(EmailNotificationData $notification): void {}

    public function publishSms(SmsNotificationData $notification): void {}

    public function publishWebsocket(WebsocketNotificationData $notification): void {}
}
