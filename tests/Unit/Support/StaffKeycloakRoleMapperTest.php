<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\StaffKeycloakRoleMapper;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StaffKeycloakRoleMapperTest extends TestCase
{
    #[Test]
    public function maps_realm_access_slug(): void
    {
        $slugs = StaffKeycloakRoleMapper::slugsFromJwt([
            'realm_access' => [
                'roles' => ['agent', 'offline_access', 'default-roles-pki-portal'],
            ],
        ]);

        $this->assertSame(['agent'], $slugs);
    }

    #[Test]
    public function maps_ui_codes_from_client_roles(): void
    {
        $slugs = StaffKeycloakRoleMapper::slugsFromJwt([
            'resource_access' => [
                'backoffice-stranger' => [
                    'roles' => ['MANAGER', 'uma_authorization'],
                ],
            ],
        ]);

        $this->assertSame(['manager'], $slugs);
    }

    #[Test]
    public function maps_configured_client_id_roles(): void
    {
        config(['keycloak.staff.client_id' => 'custom-bo']);

        $slugs = StaffKeycloakRoleMapper::slugsFromJwt([
            'resource_access' => [
                'custom-bo' => [
                    'roles' => ['RESPONSABLE_DE_VALIDATION'],
                ],
            ],
        ]);

        $this->assertSame(['responsable_de_validation'], $slugs);
    }

    #[Test]
    public function maps_administrateur_plateforme_slug_and_code(): void
    {
        $fromSlug = StaffKeycloakRoleMapper::slugsFromJwt([
            'realm_access' => ['roles' => ['administrateur_plateforme']],
        ]);
        $fromCode = StaffKeycloakRoleMapper::slugsFromJwt([
            'realm_access' => ['roles' => ['ADMINISTRATEUR_PLATEFORME']],
        ]);

        $this->assertSame(['administrateur_plateforme'], $fromSlug);
        $this->assertSame(['administrateur_plateforme'], $fromCode);
    }

    #[Test]
    public function unions_realm_and_client_roles_and_dedupes(): void
    {
        $slugs = StaffKeycloakRoleMapper::slugsFromJwt([
            'realm_access' => ['roles' => ['agent', 'AGENT']],
            'resource_access' => [
                'backoffice-stranger' => ['roles' => ['manager']],
            ],
        ]);

        $this->assertSame(['agent', 'manager'], $slugs);
    }

    #[Test]
    public function ignores_non_staff_claims_including_client(): void
    {
        $slugs = StaffKeycloakRoleMapper::slugsFromJwt([
            'realm_access' => [
                'roles' => [
                    'offline_access',
                    'default-roles-pki-portal',
                    'CLIENT',
                    'client',
                    'demandeur_authentifie',
                ],
            ],
        ]);

        $this->assertSame([], $slugs);
    }
}
