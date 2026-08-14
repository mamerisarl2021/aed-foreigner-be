<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NpiAllocator
{
    /** First NPI of the foreigner range (10 digits). */
    private const RANGE_START = 1_000_000_001;

    /** Last NPI of the foreigner range (10 digits). */
    private const RANGE_END = 1_999_999_999;

    /**
     * Allocate a foreigner NPI that starts with a digit (no F- prefix for new allocations).
     * Format: 10 digits in the 1000000001–1999999999 range.
     *
     * Only NPIs already inside that range seed the sequence: legacy 9-digit foreigner NPIs
     * and longer ANIP-issued NPIs live outside it and must not shift the next value.
     */
    public static function nextForeignerNpi(): string
    {
        return DB::transaction(function () {
            $lastNumber = User::query()
                ->whereNotNull('npi')
                ->lockForUpdate()
                ->pluck('npi')
                ->filter(fn (string $npi) => preg_match('/^[0-9]{10}$/', $npi) === 1)
                ->map(fn (string $npi) => (int) $npi)
                ->filter(fn (int $npi) => $npi >= self::RANGE_START && $npi <= self::RANGE_END)
                ->max();

            $next = $lastNumber === null ? self::RANGE_START : $lastNumber + 1;

            if ($next > self::RANGE_END) {
                throw new RuntimeException('Foreigner NPI sequence exhausted.');
            }

            return (string) $next;
        });
    }
}
