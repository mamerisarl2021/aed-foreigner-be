<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MeProfileRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            config('roles.administrateur_plateforme'),
            config('roles.agent'),
            config('roles.client'),
        ] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function me_returns_administrateur_plateforme_role_code(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(config('roles.administrateur_plateforme'));

        Sanctum::actingAs($admin);

        $this->getJson($this->api('/me'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role', 'ADMINISTRATEUR_PLATEFORME')
            ->assertJsonPath('data.email', $admin->email);
    }

    #[Test]
    public function me_returns_agent_role_code(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(config('roles.agent'));

        Sanctum::actingAs($agent);

        $this->getJson($this->api('/me'))
            ->assertOk()
            ->assertJsonPath('data.role', 'AGENT');
    }

    #[Test]
    public function me_returns_client_role_code(): void
    {
        $client = User::factory()->create();
        $client->assignRole(config('roles.client'));

        Sanctum::actingAs($client);

        $this->getJson($this->api('/me'))
            ->assertOk()
            ->assertJsonPath('data.role', 'CLIENT')
            ->assertJsonPath('data.email', $client->email);
    }

    #[Test]
    public function me_is_forbidden_when_the_view_policy_denies(): void
    {
        $client = User::factory()->create();
        $client->assignRole(config('roles.client'));
        Sanctum::actingAs($client);

        Gate::before(static fn (): bool => false);

        $this->getJson($this->api('/me'))->assertForbidden();
    }

    #[Test]
    public function me_requires_authentication(): void
    {
        $this->getJson($this->api('/me'))->assertUnauthorized();
    }
}
