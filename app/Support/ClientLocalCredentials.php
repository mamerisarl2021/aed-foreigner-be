<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Local hashes of TrustedX password/PIN so a logged-in client can prove the current secret.
 */
final class ClientLocalCredentials
{
    public const MISSING_HASH_MESSAGE = 'Aucun secret local n\'est enregistré. Utilisez la réinitialisation par e-mail.';

    public static function apply(User $user, string $type, string $plain): void
    {
        if ($type === 'pin') {
            $user->pin_hash = $plain;

            return;
        }

        $user->password = $plain;
    }

    public static function passwordIsStored(User $user): bool
    {
        return filled($user->password);
    }

    public static function pinIsStored(User $user): bool
    {
        return filled($user->pin_hash);
    }

    public static function passwordMatches(User $user, string $plain): bool
    {
        return self::passwordIsStored($user) && Hash::check($plain, $user->password);
    }

    public static function pinMatches(User $user, string $plain): bool
    {
        return self::pinIsStored($user) && Hash::check($plain, (string) $user->pin_hash);
    }

    public static function securityQuestionsConfigured(User $user): bool
    {
        $questions = $user->security_questions;

        return is_array($questions) && $questions !== [];
    }
}
