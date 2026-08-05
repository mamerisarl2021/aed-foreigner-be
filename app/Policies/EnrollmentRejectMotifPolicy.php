<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EnrollmentRejectMotif;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class EnrollmentRejectMotifPolicy
{
    use HandlesAuthorization;

    /** @return list<string> */
    private static function reviewerRoles(): array
    {
        return [
            config('roles.agent'),
            config('roles.responsable_de_validation'),
            config('roles.administrateur_plateforme'),
        ];
    }

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::reviewerRoles());
    }

    public function view(User $user, EnrollmentRejectMotif $motif): bool
    {
        return $user->hasAnyRole(self::reviewerRoles());
    }

    public function create(User $user): bool
    {
        return $user->hasRole(config('roles.administrateur_plateforme'));
    }

    public function update(User $user, EnrollmentRejectMotif $motif): bool
    {
        return $user->hasRole(config('roles.administrateur_plateforme'));
    }

    public function delete(User $user, EnrollmentRejectMotif $motif): bool
    {
        return $user->hasRole(config('roles.administrateur_plateforme'));
    }
}
