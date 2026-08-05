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
            'ADMINISTRATEUR_PLATEFORME' => config('roles.administrateur_plateforme'),
            'CLIENT' => config('roles.client'),
            'DEMANDEUR_AUTHENTIFIE' => config('roles.demandeur_authentifie'),
            default => in_array($code, self::listableSlugs(), true) ? $code : null,
        };
    }

    public static function codeFromSlug(?string $slug): ?string
    {
        return match ($slug) {
            config('roles.agent') => 'AGENT',
            config('roles.responsable_de_validation') => 'RESPONSABLE_DE_VALIDATION',
            config('roles.manager') => 'MANAGER',
            config('roles.administrateur_plateforme') => 'ADMINISTRATEUR_PLATEFORME',
            config('roles.client') => 'CLIENT',
            config('roles.demandeur_authentifie') => 'DEMANDEUR_AUTHENTIFIE',
            default => null,
        };
    }

    /**
     * Primary UI role code for the user (admin / staff / client).
     */
    public static function codeFromUser(User $user): ?string
    {
        $priority = [
            config('roles.administrateur_plateforme'),
            config('roles.responsable_de_validation'),
            config('roles.manager'),
            config('roles.agent'),
            config('roles.client'),
            config('roles.demandeur_authentifie'),
        ];

        foreach ($priority as $slug) {
            if ($slug && $user->hasRole($slug)) {
                return self::codeFromSlug($slug);
            }
        }

        return null;
    }
}
