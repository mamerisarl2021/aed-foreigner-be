<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StaffSessionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            config('roles.agent'),
            config('roles.client'),
        ] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->agent = User::factory()->create([
            'email' => 'agent-session@example.com',
            'password' => 'password',
        ]);
        $this->agent->assignRole(config('roles.agent'));

        $this->client = User::factory()->create([
            'email' => 'client-session@example.com',
            'password' => 'password',
        ]);
        $this->client->assignRole(config('roles.client'));
    }

    #[Test]
    public function client_cannot_change_staff_password_or_use_admin_logout(): void
    {
        Sanctum::actingAs($this->client);

        $this->postJson($this->api('/admin/password/change'), [
            'current_password' => 'password',
            'password' => 'NewSecret1!',
            'password_confirmation' => 'NewSecret1!',
        ])->assertForbidden();

        $this->postJson($this->api('/admins/logout'))->assertForbidden();
        $this->postJson($this->api('/admin/logout'))->assertForbidden();
    }

    #[Test]
    public function staff_can_change_password_and_logout(): void
    {
        Sanctum::actingAs($this->agent);

        $this->postJson($this->api('/admin/password/change'), [
            'current_password' => 'password',
            'password' => 'NewSecret1!',
            'password_confirmation' => 'NewSecret1!',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->agent->refresh();
        Sanctum::actingAs($this->agent);

        $this->postJson($this->api('/admin/logout'))
            ->assertOk()
            ->assertJsonPath('message', 'Déconnexion réussie.');
    }
}
