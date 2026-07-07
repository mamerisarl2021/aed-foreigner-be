<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationChannel: string
{
    case Email = 'EMAIL';
    case Sms = 'SMS';
    case Websocket = 'WEBSOCKET';
}
