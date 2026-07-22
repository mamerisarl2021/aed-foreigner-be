<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NpiAllocator
{
    public static function nextForeignerNpi(): string
    {
        return DB::transaction(function () {
            $lastNumber = User::query()
                ->whereNotNull('npi')
                ->where('npi', 'like', 'F-%')
                ->lockForUpdate()
                ->pluck('npi')
                ->map(fn (string $npi) => (int) substr($npi, 2))
                ->max() ?? 0;

            $next = $lastNumber + 1;

            if ($next > 99_999_999) {
                throw new RuntimeException('Foreigner NPI sequence exhausted.');
            }

            return 'F-'.str_pad((string) $next, 8, '0', STR_PAD_LEFT);
        });
    }
}
