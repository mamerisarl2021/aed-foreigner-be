<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuditingServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\ConsulServiceProvider;
use App\Providers\EnrollmentEventServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\NotificationServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    EventServiceProvider::class,
    ConsulServiceProvider::class,
    NotificationServiceProvider::class,
    EnrollmentEventServiceProvider::class,
    AuditingServiceProvider::class,
];
