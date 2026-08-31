<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Identity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
    public function agent_cannot_list_enrolled_persons(): void
    {
        Sanctum::actingAs($this->agent);

        $this->getJson($this->api('/admin/enrolled-persons'))->assertForbidden();
    }

    /**
     * Le pendant morale est fermé au même titre : `EnrollmentRequestPolicy`
     * ouvre tout à l'admin, mais elle ne garde pas cette route — c'est
     * `EnrolledCompanyPolicy`, qui ne connaît que l'administrateur plateforme.
     */
    #[Test]
    public function agent_cannot_list_enrolled_companies(): void
    {
        Sanctum::actingAs($this->agent);

        $this->getJson($this->api('/admin/enrolled-companies'))->assertForbidden();
    }

    #[Test]
    public function admin_can_list_enrolled_companies(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson($this->api('/admin/enrolled-companies'))->assertOk();
    }

    #[Test]
    public function client_cannot_access_stats_search_decrypt_or_update_status(): void
    {
        Sanctum::actingAs($this->client);

        $this->getJson($this->api('/stats'))->assertForbidden();
        $this->getJson($this->api('/users/search?query=test'))->assertForbidden();
        $this->getJson($this->api('/decrypt/token/file/dummy.enc'))->assertForbidden();
        $this->postJson($this->api('/admins/logout'))->assertForbidden();
        $this->postJson($this->api('/management/users/update-status'), [
            'users' => [
                ['id' => $this->client->id, 'status' => 'INACTIVE'],
            ],
        ])->assertForbidden();
    }

    #[Test]
    public function agent_can_access_stats_and_search_via_policies(): void
    {
        Sanctum::actingAs($this->agent);

        $this->getJson($this->api('/stats'))->assertOk();
        $this->getJson($this->api('/users/search?query=admin-policy'))->assertOk();
    }

    #[Test]
    public function staff_enrollment_queue_accepts_sanctum_when_gateway_keycloak_is_on(): void
    {
        config(['consul.keycloak.enabled' => true]);

        Sanctum::actingAs($this->agent);

        $this->getJson($this->api('/enrolements'))
            ->assertOk()
            ->assertJsonMissing(['message' => 'Token Keycloak invalide.']);
    }

    #[Test]
    public function guest_kyc_still_requires_infra_keycloak_when_gateway_is_on(): void
    {
        config(['consul.keycloak.enabled' => true]);

        $this->postJson($this->api('/kyc/verify'))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Token Keycloak requis.');
    }

    #[Test]
    public function morale_document_kyc_accepts_sanctum_when_gateway_keycloak_is_on(): void
    {
        config(['consul.keycloak.enabled' => true, 'services.regula.mock' => true]);

        $this->client->update(['status' => 'ACTIVE']);
        Identity::query()->create([
            'user_id' => $this->client->id,
            'type' => 'IN_PERSON',
            'level' => 'ADVANCED',
            'status' => 'APPROVED',
            'proof' => ['selfiePath' => ''],
        ]);

        Sanctum::actingAs($this->client);

        $this->postJson($this->api('/kyc/document/verify'), [
            'recto' => UploadedFile::fake()->image('recto.jpg'),
        ])
            ->assertOk()
            ->assertJsonMissing(['message' => 'Token Keycloak requis.']);
    }

    #[Test]
    public function agent_cannot_run_the_morale_document_kyc(): void
    {
        Sanctum::actingAs($this->agent);

        $this->postJson($this->api('/kyc/document/verify'), [
            'recto' => UploadedFile::fake()->image('recto.jpg'),
        ])->assertForbidden();
    }

    #[Test]
    public function admin_can_list_enrolled_persons(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson($this->api('/admin/enrolled-persons'))->assertOk();
    }

    #[Test]
    public function search_eager_loads_roles_instead_of_querying_per_user(): void
    {
        foreach (range(1, 5) as $i) {
            $user = User::factory()->create([
                'email' => "search-hit-{$i}@example.com",
                'name' => 'SearchHit',
            ]);
            $user->assignRole(config('roles.client'));
        }

        Sanctum::actingAs($this->agent);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson($this->api('/users/search?query=SearchHit&limit=5'))->assertOk();

        $roleQueries = array_values(array_filter(
            DB::getQueryLog(),
            static function (array $query): bool {
                $sql = strtolower($query['query']);

                return str_contains($sql, 'model_has_roles')
                    || str_contains($sql, 'from `roles`')
                    || str_contains($sql, 'from "roles"');
            }
        ));

        $this->assertLessThanOrEqual(3, count($roleQueries));
    }
}
