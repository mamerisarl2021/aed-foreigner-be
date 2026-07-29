<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class PhoneNumber implements ValidationRule
{
    /**
     * Strip separators so only digits and an optional leading "+" remain.
     */
    public static function normalize(string $phonenumber): string
    {
        return preg_replace('/[^\d+]/', '', trim($phonenumber)) ?? '';
    }

    public static function isValid(string $phonenumber): bool
    {
        $normalized = self::normalize($phonenumber);

        return (bool) preg_match('/^\+?\d{8,20}$/', $normalized);
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail('Le numéro de téléphone est invalide.');
        }
    }
}
