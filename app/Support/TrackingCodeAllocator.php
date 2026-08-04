<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\EnrollmentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class TrackingCodeAllocator
{
    public static function next(): string
    {
        return DB::transaction(function () {
            for ($attempt = 0; $attempt < 20; $attempt++) {
                $code = 'PK'.Str::upper(Str::random(9));

                $exists = EnrollmentRequest::query()
                    ->where('tracking_code', $code)
                    ->lockForUpdate()
                    ->exists();

                if (! $exists) {
                    return $code;
                }
            }

            throw new RuntimeException('Unable to allocate a unique tracking code.');
        });
    }
}
