<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * §9.3 — staff routes rely on policies/gates alone (no stacked role: middleware).
 */
final class PolicyFirstAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $agent;

    private User $client;

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

        $this->admin = User::factory()->create(['email' => 'admin-policy@example.com']);
        $this->admin->assignRole(config('roles.administrateur_plateforme'));

        $this->agent = User::factory()->create(['email' => 'agent-policy@example.com']);
        $this->agent->assignRole(config('roles.agent'));

        $this->client = User::factory()->create(['email' => 'client-policy@example.com']);
        $this->client->assignRole(config('roles.client'));
    }

    #[Test]
    public function agent_cannot_manage_agents_without_admin_policy(): void
    {
        Sanctum::actingAs($this->agent);

        $this->getJson($this->api('/agents'))->assertForbidden();
        $this->postJson($this->api('/agents/register'), [
            'name' => 'Doe',
            'first_name' => 'Jane',
            'email' => 'new.agent@example.com',
            'phonenumber' => '+2290162405472',
            'role' => 'AGENT',
        ])->assertForbidden();
    }

    #[Test]
    public function agent_cannot_list_enrolled_persons(): void
    {
        Sanctum::actingAs($this->agent);

        $this->getJson($this->api('/admin/enrolled-persons'))->assertForbidden();
    }

    #[Test]
    public function client_cannot_access_stats_search_decrypt_or_update_status(): void
    {
        Sanctum::actingAs($this->client);

        $this->getJson($this->api('/stats'))->assertForbidden();
        $this->getJson($this->api('/users/search?query=test'))->assertForbidden();
        $this->getJson($this->api('/decrypt/token/file/dummy.enc'))->assertForbidden();
        $this->postJson($this->api('/management/users/update-status'), [
            'users' => [
                ['id' => $this->client->id, 'status' => 'INACTIVE'],
            ],
        ])->assertForbidden();
    }

    #[Test]
    public function agent_can_access_stats_and_search_via_gates(): void
    {
        Sanctum::actingAs($this->agent);

        $this->getJson($this->api('/stats'))->assertOk();
        $this->getJson($this->api('/users/search?query=admin-policy'))->assertOk();
    }

    #[Test]
    public function admin_can_list_agents_and_enrolled_persons(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson($this->api('/agents'))->assertOk();
        $this->getJson($this->api('/admin/enrolled-persons'))->assertOk();
    }
}
