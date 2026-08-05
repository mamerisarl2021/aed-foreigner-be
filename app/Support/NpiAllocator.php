<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NpiAllocator
{
    /**
     * Allocate a foreigner NPI that starts with a digit (no F- prefix for new allocations).
     * Format: 9 digits in the 100000001–199999999 range.
     */
    public static function nextForeignerNpi(): string
    {
        return DB::transaction(function () {
            $candidates = User::query()
                ->whereNotNull('npi')
                ->lockForUpdate()
                ->pluck('npi')
                ->filter(fn (string $npi) => preg_match('/^[0-9]+$/', $npi) === 1)
                ->map(fn (string $npi) => (int) $npi);

            $lastNumber = $candidates->max() ?? 100_000_000;
            $next = max($lastNumber + 1, 100_000_001);

            if ($next > 199_999_999) {
                throw new RuntimeException('Foreigner NPI sequence exhausted.');
            }

            return (string) $next;
        });
    }
}
