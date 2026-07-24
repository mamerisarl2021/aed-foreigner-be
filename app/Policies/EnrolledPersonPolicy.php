<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class EnrolledPersonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(config('roles.administrateur_plateforme'));
    }

    public function view(User $user): bool
    {
        return $user->hasRole(config('roles.administrateur_plateforme'));
    }
}
