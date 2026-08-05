<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\ActivityLogAction;
use App\Models\ActivityLog;
use App\Models\EnrollmentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use OwenIt\Auditing\Models\Audit;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ActivityLogAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // PHPUnit runs as console; OwenIt skips audits unless console auditing is on.
        config(['audit.enabled' => true, 'audit.console' => true]);

        foreach ([
            config('roles.administrateur_plateforme'),
            config('roles.agent'),
        ] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create(['email' => 'admin-logs@example.com']);
        $this->admin->assignRole(config('roles.administrateur_plateforme'));

        $this->agent = User::factory()->create(['email' => 'agent-logs@example.com']);
        $this->agent->assignRole(config('roles.agent'));
    }

    #[Test]
    public function admin_can_list_and_show_activity_logs(): void
    {
        $log = ActivityLog::query()->create([
            'action_code' => ActivityLogAction::PriseEnChargeAgent->label(),
            'description' => 'Agent Test a pris en charge la demande PK123.',
            'actor_user_id' => $this->agent->id,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson($this->api('/admin/activity-logs'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'id' => $log->id,
                'action' => ActivityLogAction::PriseEnChargeAgent->label(),
            ]);

        $this->getJson($this->api("/admin/activity-logs/{$log->id}"))
            ->assertOk()
            ->assertJsonPath('data.id', $log->id)
            ->assertJsonPath('data.actor.id', $this->agent->id)
            ->assertJsonPath('data.description', 'Agent Test a pris en charge la demande PK123.');
    }

    #[Test]
    public function recorded_actions_capture_the_request_ip(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->admin);

        $this->postJson($this->api('/agents/register'), [
            'name' => 'KOTO',
            'first_name' => 'Ada',
            'email' => 'ada.koto@example.com',
            'phonenumber' => '+2290162405472',
            'role' => 'AGENT',
        ])->assertSuccessful();

        $log = ActivityLog::query()
            ->where('action_code', ActivityLogAction::UtilisateurCree->label())
            ->firstOrFail();

        $this->assertSame('127.0.0.1', $log->ip_address);

        $this->getJson($this->api("/admin/activity-logs/{$log->id}"))
            ->assertOk()
            ->assertJsonPath('data.ip_address', '127.0.0.1')
            ->assertJsonPath('data.actor.id', $this->admin->id);
    }

    #[Test]
    public function activity_log_detail_carries_the_enrollment_and_its_metadata(): void
    {
        $enrollment = EnrollmentRequest::query()->create([
            'email' => 'applicant-detail@example.com',
            'phonenumber' => '+2290162405472',
            'status' => 'EN_ATTENTE',
            'type' => 'PERSONNE_PHYSIQUE',
            'kyc_data' => ['nom' => 'Test'],
        ]);

        $log = ActivityLog::query()->create([
            'action_code' => ActivityLogAction::RejetAgent->label(),
            'description' => 'Agent Test a rejeté la demande.',
            'actor_user_id' => $this->agent->id,
            'enrollment_request_id' => $enrollment->id,
            'metadata' => ['motif_id' => 'abc'],
            'created_at' => now(),
        ]);

        Sanctum::actingAs($this->admin);

        // Écrit hors requête HTTP : la colonne reste nulle, et la ressource
        // l'expose telle quelle plutôt que d'inventer une adresse.
        $this->getJson($this->api("/admin/activity-logs/{$log->id}"))
            ->assertOk()
            ->assertJsonPath('data.enrollment_request_id', $enrollment->id)
            ->assertJsonPath('data.metadata.motif_id', 'abc')
            ->assertJsonPath('data.ip_address', null);
    }

    #[Test]
    public function agent_cannot_show_an_activity_log(): void
    {
        $log = ActivityLog::query()->create([
            'action_code' => ActivityLogAction::ValidationAgent->label(),
            'description' => 'Agent Test a validé la demande.',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($this->agent);

        $this->getJson($this->api("/admin/activity-logs/{$log->id}"))->assertForbidden();
    }

    #[Test]
    public function an_unknown_activity_log_is_rejected_by_the_form_request(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson($this->api('/admin/activity-logs/'.Str::uuid()))->assertStatus(422);
    }

    #[Test]
    public function search_q_matches_action_or_description(): void
    {
        ActivityLog::query()->create([
            'action_code' => ActivityLogAction::ValidationAgent->label(),
            'description' => 'UniquePhraseXYZ a validé la demande.',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson($this->api('/admin/activity-logs?q=UniquePhraseXYZ'))
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $this->getJson($this->api('/admin/activity-logs?q=VALIDATION'))
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
    }

    #[Test]
    public function agent_cannot_access_activity_logs(): void
    {
        Sanctum::actingAs($this->agent);

        $this->getJson($this->api('/admin/activity-logs'))->assertForbidden();
    }

    #[Test]
    public function auditeur_role_is_rejected_on_register(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->api('/agents/register'), [
            'name' => 'Doe',
            'first_name' => 'Jane',
            'email' => 'jane.auditeur@example.com',
            'phonenumber' => '+2290162405472',
            'role' => 'AUDITEUR',
        ])->assertStatus(422);
    }

    #[Test]
    public function enrollment_request_mutations_create_owenit_audits(): void
    {
        $enrollment = EnrollmentRequest::query()->create([
            'email' => 'applicant@example.com',
            'phonenumber' => '+2290162405472',
            'status' => 'EN_ATTENTE',
            'type' => 'PERSONNE_PHYSIQUE',
            'kyc_data' => ['nom' => 'Test'],
        ]);

        $enrollment->update(['status' => 'VALIDATION_AGENT']);

        $this->assertTrue(
            Audit::query()
                ->where('auditable_type', EnrollmentRequest::class)
                ->where('auditable_id', $enrollment->id)
                ->exists()
        );
    }
}
