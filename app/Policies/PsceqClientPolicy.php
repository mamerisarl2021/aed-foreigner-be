<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PsceqClient;
use App\Models\User;

final class PsceqClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(config('roles.administrateur_plateforme'));
    }

    public function view(User $user, PsceqClient $client): bool
    {
        return $user->hasRole(config('roles.administrateur_plateforme'));
    }

    public function create(User $user): bool
    {
        return $user->hasRole(config('roles.administrateur_plateforme'));
    }

    public function update(User $user, PsceqClient $client): bool
    {
        return $user->hasRole(config('roles.administrateur_plateforme'));
    }

    public function delete(User $user, PsceqClient $client): bool
    {
        return $user->hasRole(config('roles.administrateur_plateforme'));
    }

    public function revoke(User $user, PsceqClient $client): bool
    {
        return $user->hasRole(config('roles.administrateur_plateforme'));
    }
}
