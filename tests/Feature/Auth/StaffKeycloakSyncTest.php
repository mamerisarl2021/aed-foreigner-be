<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Jobs\WelcomeAgentJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StaffKeycloakSyncTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://demo-oauth.qcdigitalhub.com/realms/pki-portal';

    private const TOKEN_URI = 'https://demo-oauth.qcdigitalhub.com/realms/pki-portal/protocol/openid-connect/token';

    private const ADMIN_BASE = 'https://demo-oauth.qcdigitalhub.com/admin/realms/pki-portal';

    private const USER_ID = 'kc-user-1';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        foreach ([
            config('roles.administrateur_plateforme'),
            config('roles.agent'),
            config('roles.manager'),
        ] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create(['email' => 'platform.admin@example.com']);
        $this->admin->assignRole(config('roles.administrateur_plateforme'));

        config([
            'keycloak.staff.enabled' => true,
            'keycloak.staff.issuer' => self::ISSUER,
            'keycloak.staff.token_uri' => self::TOKEN_URI,
            'keycloak.staff.admin_client_id' => 'backoffice-staff-admin',
            'keycloak.staff.admin_client_secret' => 'test-admin-secret',
        ]);
    }

    #[Test]
    public function register_pushes_user_to_keycloak_and_skips_welcome_job(): void
    {
        Bus::fake();
        $this->fakeAdminApi();
        Sanctum::actingAs($this->admin);

        $this->postJson($this->api('/agents/register'), $this->agentPayload())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'ada.koto@example.com');

        $this->assertDatabaseHas('users', ['email' => 'ada.koto@example.com']);
        Bus::assertNotDispatched(WelcomeAgentJob::class);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $this->path($request) === '/admin/realms/pki-portal/users');
    }

    #[Test]
    public function register_rolls_back_local_user_when_keycloak_fails(): void
    {
        Bus::fake();
        $this->fakeAdminApi(createStatus: 500);
        Sanctum::actingAs($this->admin);

        $this->postJson($this->api('/agents/register'), $this->agentPayload())
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('users', ['email' => 'ada.koto@example.com']);
        Bus::assertNotDispatched(WelcomeAgentJob::class);
    }

    #[Test]
    public function register_returns_503_when_admin_secret_is_missing(): void
    {
        Bus::fake();
        Http::fake();
        config(['keycloak.staff.admin_client_secret' => null]);
        Sanctum::actingAs($this->admin);

        $this->postJson($this->api('/agents/register'), $this->agentPayload())
            ->assertStatus(503);

        $this->assertDatabaseMissing('users', ['email' => 'ada.koto@example.com']);
    }

    #[Test]
    public function update_and_delete_call_keycloak_admin_api(): void
    {
        $this->fakeAdminApi(existing: true);
        Sanctum::actingAs($this->admin);

        $agent = User::factory()->create(['email' => 'ada.koto@example.com']);
        $agent->assignRole(config('roles.agent'));

        $this->postJson($this->api('/agents/'.$agent->id), [
            'name' => 'KOTO',
            'role' => 'MANAGER',
        ])->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $this->path($request) === '/admin/realms/pki-portal/users/'.self::USER_ID);

        $agent->refresh();
        $this->assertTrue($agent->hasRole(config('roles.manager')));

        $this->deleteJson($this->api('/agents/'.$agent->id))->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $this->path($request) === '/admin/realms/pki-portal/users/'.self::USER_ID);
        $this->assertDatabaseMissing('users', ['id' => $agent->id]);
    }

    #[Test]
    public function delete_keeps_local_user_when_keycloak_fails(): void
    {
        $this->fakeAdminApi(existing: true, deleteStatus: 500);
        Sanctum::actingAs($this->admin);

        $agent = User::factory()->create(['email' => 'ada.koto@example.com']);
        $agent->assignRole(config('roles.agent'));

        $this->deleteJson($this->api('/agents/'.$agent->id))
            ->assertStatus(502);

        $this->assertDatabaseHas('users', ['id' => $agent->id]);
    }

    #[Test]
    public function register_does_not_call_admin_api_when_flag_is_off(): void
    {
        config(['keycloak.staff.enabled' => false]);
        Bus::fake();
        Http::fake();
        Sanctum::actingAs($this->admin);

        $this->postJson($this->api('/agents/register'), $this->agentPayload())
            ->assertOk();

        Bus::assertDispatched(WelcomeAgentJob::class);
        Http::assertNothingSent();
    }

    /**
     * @return array{name: string, first_name: string, email: string, phonenumber: string, role: string}
     */
    private function agentPayload(): array
    {
        return [
            'name' => 'KOTO',
            'first_name' => 'Ada',
            'email' => 'ada.koto@example.com',
            'phonenumber' => '+2290162405472',
            'role' => 'AGENT',
        ];
    }

    private function fakeAdminApi(bool $existing = false, int $createStatus = 201, int $deleteStatus = 204): void
    {
        Http::fake(function (Request $request) use ($existing, $createStatus, $deleteStatus) {
            $path = $this->path($request);
            $method = $request->method();

            if ($method === 'POST' && str_ends_with($path, '/protocol/openid-connect/token')) {
                return Http::response(['access_token' => 'admin-token', 'expires_in' => 300]);
            }

            if ($method === 'GET' && $path === '/admin/realms/pki-portal/users') {
                if (! $existing) {
                    return Http::response([]);
                }

                return Http::response([['id' => self::USER_ID, 'email' => 'ada.koto@example.com']]);
            }

            if ($method === 'POST' && $path === '/admin/realms/pki-portal/users') {
                return Http::response([], $createStatus, [
                    'Location' => self::ADMIN_BASE.'/users/'.self::USER_ID,
                ]);
            }

            if ($method === 'PUT' && $path === '/admin/realms/pki-portal/users/'.self::USER_ID) {
                return Http::response([], 204);
            }

            if ($method === 'GET' && str_starts_with($path, '/admin/realms/pki-portal/roles/')) {
                $name = basename($path);

                return Http::response(['id' => 'role-'.$name, 'name' => $name]);
            }

            if ($method === 'GET' && str_ends_with($path, '/role-mappings/realm')) {
                if (! $existing) {
                    return Http::response([]);
                }

                return Http::response([['id' => 'role-agent', 'name' => 'agent']]);
            }

            if (in_array($method, ['POST', 'DELETE'], true) && str_ends_with($path, '/role-mappings/realm')) {
                return Http::response([], 204);
            }

            if ($method === 'PUT' && str_ends_with($path, '/execute-actions-email')) {
                return Http::response([], 204);
            }

            if ($method === 'PUT' && str_ends_with($path, '/reset-password')) {
                return Http::response([], 204);
            }

            if ($method === 'DELETE' && $path === '/admin/realms/pki-portal/users/'.self::USER_ID) {
                return Http::response([], $deleteStatus);
            }

            return Http::response(['error' => 'unmocked '.$method.' '.$path], 599);
        });
    }

    private function path(Request $request): string
    {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }
}
