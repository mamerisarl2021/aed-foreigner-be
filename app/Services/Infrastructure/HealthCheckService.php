<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\DB;
use Throwable;

final class HealthCheckService
{
    public function isDatabaseUp(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
