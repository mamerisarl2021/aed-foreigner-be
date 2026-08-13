<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\EnrolledCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class CompanyIdentifiantAllocator
{
    public static function next(): string
    {
        return DB::transaction(function () {
            for ($attempt = 0; $attempt < 20; $attempt++) {
                $code = 'PM'.Str::upper(Str::random(9));

                $exists = EnrolledCompany::query()
                    ->where('identifiant', $code)
                    ->lockForUpdate()
                    ->exists();

                if (! $exists) {
                    return $code;
                }
            }

            throw new RuntimeException('Unable to allocate a unique company identifiant.');
        });
    }
}
