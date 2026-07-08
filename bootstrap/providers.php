<?php

declare(strict_types=1);
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\ConsulServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\NotificationServiceProvider;
use OwenIt\Auditing\AuditingServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    EventServiceProvider::class,
    ConsulServiceProvider::class,
    NotificationServiceProvider::class,
    AuditingServiceProvider::class,
];
