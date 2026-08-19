<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /** @return list<string> */
    private static function staffRoles(): array
    {
        return config('roles.staff', []);
    }

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::staffRoles());
    }

    public function view(User $user, User $target): bool
    {
        return $user->id === $target->id || $user->hasAnyRole(self::staffRoles());
    }

    public function search(User $user): bool
    {
        return $user->hasAnyRole(self::staffRoles());
    }

    public function update(User $user, User $target): bool
    {
        return $user->id === $target->id
            || $user->hasRole(config('roles.administrateur_plateforme'));
    }

    public function delete(User $user, User $target): bool
    {
        return $user->hasRole(config('roles.administrateur_plateforme'));
    }

    public function updateStatus(User $user): bool
    {
        return $user->hasAnyRole([
            config('roles.agent'),
            config('roles.responsable_de_validation'),
            config('roles.administrateur_plateforme'),
        ]);
    }

    public function setClientPassword(User $user): bool
    {
        return $user->hasRole(config('roles.administrateur_plateforme'));
    }

    public function manageClientSecurity(User $user): bool
    {
        return $user->hasRole(config('roles.client'));
    }

    public function manageStaff(User $user): bool
    {
        return $user->hasRole(config('roles.administrateur_plateforme'));
    }

    public function changeStaffPassword(User $user): bool
    {
        return $user->hasAnyRole(self::staffRoles());
    }

    public function logoutStaff(User $user): bool
    {
        return $user->hasAnyRole(self::staffRoles());
    }
}
