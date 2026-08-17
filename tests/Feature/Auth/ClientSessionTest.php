<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\ActivityLogAction;
use App\Models\ActivityLog;
use App\Models\Identity;
use App\Models\User;
use App\Services\PKI\TrustedXClientService;
use App\Services\Registration\UserRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ClientSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([config('roles.client'), config('roles.agent')] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->client = User::factory()->create([
            'email' => 'client-session@example.com',
            'npi' => '1234567890123',
            'status' => 'ACTIVE',
            'password' => 'password',
            'pin_hash' => '1234',
            'security_questions' => [
                ['question' => 'Ville de naissance ?', 'answer' => 'Cotonou'],
            ],
        ]);
        $this->client->assignRole(config('roles.client'));

        $this->agent = User::factory()->create([
            'email' => 'agent-session@example.com',
            'status' => 'ACTIVE',
        ]);
        $this->agent->assignRole(config('roles.agent'));
    }

    #[Test]
    public function me_exposes_identites_and_security_flags_for_a_client(): void
    {
        Identity::query()->create([
            'user_id' => $this->client->id,
            'type' => 'IN_PERSON',
            'level' => 'ADVANCED',
            'status' => 'APPROVED',
            'date' => '2026-08-01',
            'proof' => ['selfiePath' => ''],
        ]);
        Identity::query()->create([
            'user_id' => $this->client->id,
            'type' => 'PERSONNE_MORALE',
            'level' => 'ADVANCED',
            'status' => 'APPROVED',
            'proof' => ['company' => []],
        ]);

        Sanctum::actingAs($this->client);

        $this->getJson($this->api('/me'))
            ->assertOk()
            ->assertJsonPath('data.role', 'CLIENT')
            ->assertJsonPath('data.questions_secretes_configurees', true)
            ->assertJsonPath('data.pin_configure', true)
            ->assertJsonPath('data.identites.0.type', 'IN_PERSON')
            ->assertJsonPath('data.identites.0.statut', 'APPROVED')
            ->assertJsonPath('data.identites.0.niveau', 'ADVANCED')
            ->assertJsonPath('data.identites.0.date', '2026-08-01')
            ->assertJsonPath('data.identites.1.type', 'PERSONNE_MORALE')
            ->assertJsonMissingPath('data.identites.0.selfieUrl')
            ->assertJsonMissingPath('data.identites.0.proof');
    }

    #[Test]
    public function me_returns_empty_identites_for_staff(): void
    {
        Sanctum::actingAs($this->agent);

        $this->getJson($this->api('/me'))
            ->assertOk()
            ->assertJsonPath('data.role', 'AGENT')
            ->assertJsonPath('data.identites', [])
            ->assertJsonPath('data.pin_configure', false)
            ->assertJsonPath('data.questions_secretes_configurees', false);
    }

    #[Test]
    public function client_logout_revokes_the_current_token(): void
    {
        $token = $this->client->createToken('web')->plainTextToken;

        $this->withToken($token)
            ->postJson($this->api('/clients/logout'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(
            ActivityLog::query()
                ->where('action_code', ActivityLogAction::DeconnexionClient->label())
                ->where('actor_user_id', $this->client->id)
                ->exists()
        );

        $this->assertSame(0, $this->client->tokens()->count());

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson($this->api('/me'))
            ->assertUnauthorized();
    }

    #[Test]
    public function staff_cannot_use_client_logout(): void
    {
        Sanctum::actingAs($this->agent);

        $this->postJson($this->api('/clients/logout'))->assertForbidden();
    }

    #[Test]
    public function client_login_sets_last_login_at(): void
    {
        $this->assertNull($this->client->last_login_at);

        $this->mock(TrustedXClientService::class, function ($mock) {
            $mock->shouldReceive('userInfo')
                ->once()
                ->andReturn([
                    'status' => true,
                    'data' => [
                        'user' => $this->client,
                        'token' => 'sanctum-token',
                        'pki_token' => 'pki-token',
                    ],
                ]);
        });

        $result = app(UserRegistrationService::class)->login('auth-code');

        $this->assertTrue($result->success);
        $this->client->refresh();
        $this->assertNotNull($this->client->last_login_at);
    }
}
