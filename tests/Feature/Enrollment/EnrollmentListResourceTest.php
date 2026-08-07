<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\AgentAvis;
use App\Enums\EnrollmentStatus;
use App\Models\EnrollmentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class EnrollmentListResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => config('roles.agent'), 'guard_name' => 'web']);

        $this->agent = User::factory()->create(['email' => 'agent-list@example.com']);
        $this->agent->assignRole(config('roles.agent'));
    }

    /**
     * Un retour du responsable remet la demande en EN_ATTENTE_AGENT : seule la date de
     * retour la distingue d'une demande jamais instruite, et le backoffice s'en
     * sert pour l'afficher « à corriger ».
     */
    #[Test]
    public function the_agent_list_row_carries_the_decision_and_return_dates(): void
    {
        $enrollment = EnrollmentRequest::query()->create([
            'email' => 'applicant-list@example.com',
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::EnAttenteAgent->value,
            'type' => 'PERSONNE_PHYSIQUE',
            'kyc_data' => ['name' => 'KOTO', 'first_name' => 'Ada'],
            'agent_decided_at' => now()->subDay(),
            'returned_at' => now(),
        ]);

        Sanctum::actingAs($this->agent);

        $this->getJson($this->api('/enrolements'))
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $enrollment->id)
            ->assertJsonPath('data.data.0.demandeur.nom', 'KOTO')
            ->assertJsonPath('data.data.0.date_decision', $enrollment->agent_decided_at?->toJSON())
            ->assertJsonPath('data.data.0.retournee_le', $enrollment->returned_at?->toJSON());
    }

    #[Test]
    public function both_dates_stay_null_on_a_request_nobody_has_touched(): void
    {
        EnrollmentRequest::query()->create([
            'email' => 'applicant-fresh@example.com',
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::EnAttenteAgent->value,
            'type' => 'PERSONNE_PHYSIQUE',
            'kyc_data' => ['name' => 'MENSAH', 'first_name' => 'Rita'],
        ]);

        Sanctum::actingAs($this->agent);

        $this->getJson($this->api('/enrolements'))
            ->assertOk()
            ->assertJsonPath('data.data.0.date_decision', null)
            ->assertJsonPath('data.data.0.retournee_le', null);
    }

    #[Test]
    public function show_rejects_invalid_enrollment_uuid(): void
    {
        Sanctum::actingAs($this->agent);

        $this->getJson($this->api('/enrolements/not-a-uuid'))
            ->assertStatus(422);
    }

    #[Test]
    public function the_list_can_be_filtered_by_agent_avis(): void
    {
        Role::firstOrCreate(['name' => config('roles.responsable_de_validation'), 'guard_name' => 'web']);
        $responsable = User::factory()->create(['email' => 'responsable-list-avis@example.com']);
        $responsable->assignRole(config('roles.responsable_de_validation'));

        $favorable = EnrollmentRequest::query()->create([
            'email' => 'favorable@example.com',
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::EnAttenteResponsable->value,
            'agent_avis' => AgentAvis::Favorable->value,
            'type' => 'PERSONNE_PHYSIQUE',
            'kyc_data' => ['name' => 'FAV', 'first_name' => 'Ada'],
        ]);

        EnrollmentRequest::query()->create([
            'email' => 'defavorable@example.com',
            'phonenumber' => '+2290162405473',
            'status' => EnrollmentStatus::EnAttenteResponsable->value,
            'agent_avis' => AgentAvis::Defavorable->value,
            'type' => 'PERSONNE_PHYSIQUE',
            'kyc_data' => ['name' => 'DEF', 'first_name' => 'Bob'],
        ]);

        Sanctum::actingAs($responsable);

        $this->getJson($this->api('/enrolements?avis=FAVORABLE'))
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $favorable->id)
            ->assertJsonPath('data.data.0.avis_agent', 'FAVORABLE');
    }
}
