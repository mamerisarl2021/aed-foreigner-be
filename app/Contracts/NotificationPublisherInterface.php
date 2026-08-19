<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\EmailNotificationData;
use App\DataTransferObjects\SmsNotificationData;
use App\DataTransferObjects\WebsocketNotificationData;

interface NotificationPublisherInterface
{
    public function publishEmail(EmailNotificationData $notification): void;

    public function publishSms(SmsNotificationData $notification): void;

    public function publishWebsocket(WebsocketNotificationData $notification): void;
}
