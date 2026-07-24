<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

final class StaffRoleMapper
{
    /** @return list<string> */
    public static function listableSlugs(): array
    {
        return array_values(array_filter(
            config('roles.staff', []),
            fn (string $role) => $role !== config('roles.administrateur_plateforme')
        ));
    }

    public static function slugFromCode(?string $code): ?string
    {
        return match ($code) {
            'AGENT' => config('roles.agent'),
            'RESPONSABLE_DE_VALIDATION' => config('roles.responsable_de_validation'),
            'MANAGER' => config('roles.manager'),
            'AUDITEUR' => config('roles.auditeur'),
            default => in_array($code, self::listableSlugs(), true) ? $code : null,
        };
    }

    public static function codeFromSlug(?string $slug): ?string
    {
        return match ($slug) {
            config('roles.agent') => 'AGENT',
            config('roles.responsable_de_validation') => 'RESPONSABLE_DE_VALIDATION',
            config('roles.manager') => 'MANAGER',
            config('roles.auditeur') => 'AUDITEUR',
            default => null,
        };
    }

    public static function codeFromUser(User $user): ?string
    {
        foreach (self::listableSlugs() as $slug) {
            if ($user->hasRole($slug)) {
                return self::codeFromSlug($slug);
            }
        }

        return null;
    }
}
