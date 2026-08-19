<?php

declare(strict_types=1);

namespace App\Support;

final class StaffKeycloakRoleMapper
{
    /**
     * Spatie staff slugs present on a Keycloak access token.
     *
     * Collects realm_access.roles plus resource_access for backoffice-stranger
     * and the configured staff client_id. UI codes (AGENT, …) map to slugs.
     * Noise such as default-roles-pki-portal / offline_access is ignored.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public static function slugsFromJwt(array $payload): array
    {
        $slugs = [];

        foreach (self::rawRoleClaims($payload) as $claim) {
            $slug = self::slugFromClaim($claim);
            if ($slug === null) {
                continue;
            }
            $slugs[$slug] = $slug;
        }

        return array_values($slugs);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private static function rawRoleClaims(array $payload): array
    {
        $roles = [];

        $realmAccess = $payload['realm_access'] ?? null;
        if (is_array($realmAccess)) {
            $roles = array_merge($roles, self::stringList($realmAccess['roles'] ?? null));
        }

        $resourceAccess = $payload['resource_access'] ?? null;
        if (! is_array($resourceAccess)) {
            return $roles;
        }

        foreach (self::clientIds() as $clientId) {
            $client = $resourceAccess[$clientId] ?? null;
            if (! is_array($client)) {
                continue;
            }
            $roles = array_merge($roles, self::stringList($client['roles'] ?? null));
        }

        return $roles;
    }

    /** @return list<string> */
    private static function clientIds(): array
    {
        $ids = ['backoffice-stranger'];
        $configured = config('keycloak.staff.client_id');
        if (is_string($configured) && $configured !== '' && ! in_array($configured, $ids, true)) {
            $ids[] = $configured;
        }

        return $ids;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    private static function slugFromClaim(string $claim): ?string
    {
        /** @var list<string> $staff */
        $staff = config('roles.staff', []);

        if (in_array($claim, $staff, true)) {
            return $claim;
        }

        $lower = strtolower($claim);
        if (in_array($lower, $staff, true)) {
            return $lower;
        }

        $fromCode = StaffRoleMapper::slugFromCode($claim);
        if (is_string($fromCode) && in_array($fromCode, $staff, true)) {
            return $fromCode;
        }

        return null;
    }
}
