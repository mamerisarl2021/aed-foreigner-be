<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\AuthServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Providers\ConsulServiceProvider::class,
    App\Providers\NotificationServiceProvider::class,
    OwenIt\Auditing\AuditingServiceProvider::class,
];
