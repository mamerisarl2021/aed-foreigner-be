<?php

declare(strict_types=1);

namespace App\Rules\Enrollment;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * ISO-8601 instant (timezone required) within the KYC capture window.
 */
class Iso8601Instant implements ValidationRule
{
    public const MAX_MINUTES_PAST = 60;

    public const MAX_MINUTES_FUTURE = 5;

    private const PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('La date de capture du selfie est invalide.');

            return;
        }

        if (preg_match(self::PATTERN, $value) !== 1) {
            $fail('La date de capture du selfie doit être un instant ISO-8601 (ex. 2026-08-17T14:30:00+01:00).');

            return;
        }

        try {
            $instant = Carbon::parse($value);
        } catch (Throwable) {
            $fail('La date de capture du selfie est invalide.');

            return;
        }

        $earliest = now()->subMinutes(self::MAX_MINUTES_PAST);
        $latest = now()->addMinutes(self::MAX_MINUTES_FUTURE);

        if ($instant->lt($earliest) || $instant->gt($latest)) {
            $fail('La date de capture du selfie doit correspondre à la capture KYC récente.');
        }
    }
}
