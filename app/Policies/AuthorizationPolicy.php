<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Identity;
use App\Models\OTP;
use App\Models\User;

class AuthorizationPolicy
{
    public function before(User $user): ?bool
    {
        if ($user->hasRole(config('roles.administrateur_plateforme'))) {
            return true;
        }

        return null;
    }

    public function viewIdentity(User $user, Identity $identity): bool
    {
        return $identity->user_id === $user->id;
    }

    public function updateIdentity(User $user, Identity $identity): bool
    {
        return $identity->user_id === $user->id;
    }

    public function deleteIdentity(User $user, Identity $identity): bool
    {
        return $identity->user_id === $user->id;
    }

    public function viewOtp(User $user, OTP $otp): bool
    {
        return $user->email === $otp->email || $user->npi === $otp->npi;
    }
}
