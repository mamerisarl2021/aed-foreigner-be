<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationPlatform: string
{
    case Portal = 'PORTAL';
    case Sandbox = 'SANDBOX';
}
