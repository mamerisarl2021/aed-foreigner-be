<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class ActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(config('roles.administrateur_plateforme'));
    }
}
