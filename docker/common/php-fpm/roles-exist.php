#!/usr/bin/env php
<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

require '/var/www/vendor/autoload.php';

$app = require '/var/www/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! Schema::hasTable('roles')) {
    exit(1);
}

exit(Role::query()->exists() ? 0 : 1);
