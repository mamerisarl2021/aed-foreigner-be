<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class PlatformPolicy
{
    public function viewStats(User $user): bool
    {
        return $user->hasAnyRole([
            config('roles.administrateur_plateforme'),
            config('roles.agent'),
            config('roles.responsable_de_validation'),
            config('roles.manager'),
        ]);
    }

    public function viewAudits(User $user): bool
    {
        return $user->hasRole(config('roles.administrateur_plateforme'));
    }

    public function viewEncryptedDocuments(User $user): bool
    {
        return $user->hasAnyRole([
            config('roles.administrateur_plateforme'),
            config('roles.agent'),
            config('roles.responsable_de_validation'),
            config('roles.manager'),
        ]);
    }

    public function viewApiDocs(User $user): bool
    {
        return $user->hasAnyRole(config('roles.staff', []));
    }
}
