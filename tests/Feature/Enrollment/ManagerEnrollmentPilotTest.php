<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Models\EnrollmentRejectMotif;
use App\Models\EnrollmentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ManagerEnrollmentPilotTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => config('roles.manager'), 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => config('roles.agent'), 'guard_name' => 'web']);

        $this->manager = User::factory()->create(['email' => 'manager-pilot@example.com']);
        $this->manager->assignRole(config('roles.manager'));

        $this->agent = User::factory()->create(['email' => 'agent-pilot@example.com']);
        $this->agent->assignRole(config('roles.agent'));
    }

    #[Test]
    public function an_agent_cannot_list_manager_physique_enrollments(): void
    {
        Sanctum::actingAs($this->agent);

        $this->getJson($this->api('/management/enrolements/physiques'))
            ->assertForbidden();
    }

    #[Test]
    public function the_physique_list_excludes_personne_morale_rows(): void
    {
        $physique = $this->enrollment(EnrollmentStatus::EnAttenteAgent, 'PERSONNE_PHYSIQUE', [
            'email' => 'pp@example.com',
            'kyc_data' => ['name' => 'AGBO', 'first_name' => 'Fifame'],
        ]);
        $this->enrollment(EnrollmentStatus::EnAttenteAgent, 'PERSONNE_MORALE', [
            'email' => 'pm@example.com',
            'kyc_data' => ['legal_name' => 'TECH SARL'],
        ]);

        Sanctum::actingAs($this->manager);

        $this->getJson($this->api('/management/enrolements/physiques'))
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $physique->id)
            ->assertJsonPath('data.data.0.demandeur.nom', 'AGBO');
    }

    #[Test]
    public function the_default_physique_list_includes_enrolee(): void
    {
        $enrolee = $this->enrollment(EnrollmentStatus::Enrolee, 'PERSONNE_PHYSIQUE', [
            'email' => 'enrolee@example.com',
            'kyc_data' => ['name' => 'KOTO', 'first_name' => 'Ada'],
        ]);

        Sanctum::actingAs($this->manager);

        $this->getJson($this->api('/management/enrolements/physiques'))
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $enrolee->id)
            ->assertJsonPath('data.data.0.statut', EnrollmentStatus::Enrolee->value);
    }

    #[Test]
    public function list_rows_expose_responsable_and_elapsed_days(): void
    {
        Role::firstOrCreate(['name' => config('roles.responsable_de_validation'), 'guard_name' => 'web']);
        $responsable = User::factory()->create([
            'name' => 'DOSSOU',
            'first_name' => 'Marc',
            'email' => 'resp-pilot@example.com',
        ]);
        $responsable->assignRole(config('roles.responsable_de_validation'));

        $enrollment = $this->enrollment(EnrollmentStatus::EnCoursResponsable, 'PERSONNE_PHYSIQUE', [
            'email' => 'delai@example.com',
            'assigned_agent_id' => $this->agent->id,
            'assigned_responsable_id' => $responsable->id,
            'kyc_data' => ['name' => 'MENSAH', 'first_name' => 'Rita'],
        ]);
        $enrollment->created_at = now()->subDays(3)->startOfDay();
        $enrollment->save();

        Sanctum::actingAs($this->manager);

        $this->getJson($this->api('/management/enrolements/physiques'))
            ->assertOk()
            ->assertJsonPath('data.data.0.agent.nom', $this->agent->name)
            ->assertJsonPath('data.data.0.responsable.nom', 'DOSSOU')
            ->assertJsonPath('data.data.0.delai_ecoule_jours', 3);
    }

    #[Test]
    public function showing_a_morale_id_on_the_physique_route_is_not_found(): void
    {
        $morale = $this->enrollment(EnrollmentStatus::EnAttenteAgent, 'PERSONNE_MORALE', [
            'email' => 'wrong-type@example.com',
            'kyc_data' => ['legal_name' => 'ACME SA'],
        ]);

        Sanctum::actingAs($this->manager);

        $this->getJson($this->api('/management/enrolements/physiques/'.$morale->id))
            ->assertNotFound()
            ->assertJsonPath('message', 'Demande introuvable.');
    }

    #[Test]
    public function morale_detail_exposes_company_fields_without_kyc_analysis(): void
    {
        $submitter = User::factory()->create([
            'name' => 'KOTO',
            'first_name' => 'Ada',
            'email' => 'pm-owner@example.com',
        ]);
        $morale = $this->enrollment(EnrollmentStatus::EnAttenteAgent, 'PERSONNE_MORALE', [
            'email' => 'entreprise@example.com',
            'submitted_by_user_id' => $submitter->id,
            'kyc_data' => [
                'legal_name' => 'TECH SARL INNOV',
                'country_of_incorporation' => 'Canada',
                'legal_form' => 'SARL',
            ],
        ]);

        Sanctum::actingAs($this->manager);

        $this->getJson($this->api('/management/enrolements/morales/'.$morale->id))
            ->assertOk()
            ->assertJsonPath('data.informations_entreprise.raison_sociale', 'TECH SARL INNOV')
            ->assertJsonMissingPath('data.analyse_kyc')
            ->assertJsonMissingPath('data.peut_instruire');
    }

    #[Test]
    public function dashboard_stats_include_motif_titles_and_evolution(): void
    {
        $motif = EnrollmentRejectMotif::query()->create([
            'title' => 'Pièces justificatives illisibles',
            'description' => 'Document illisible',
        ]);
        $this->enrollment(EnrollmentStatus::Rejetee, 'PERSONNE_PHYSIQUE', [
            'email' => 'rejete@example.com',
            'reject_reasons' => [$motif->id],
            'kyc_data' => ['name' => 'REJ', 'first_name' => 'A'],
        ]);
        $this->enrollment(EnrollmentStatus::Enrolee, 'PERSONNE_PHYSIQUE', [
            'email' => 'ok@example.com',
            'kyc_data' => ['name' => 'OK', 'first_name' => 'B'],
        ]);

        Sanctum::actingAs($this->manager);

        $this->getJson($this->api('/management/enrollment-stats'))
            ->assertOk()
            ->assertJsonPath('data.counts.received', 2)
            ->assertJsonPath('data.counts.rejected', 1)
            ->assertJsonPath('data.taux_rejet_global', 50)
            ->assertJsonPath('data.reject_rate_by_motif.0.title', 'Pièces justificatives illisibles')
            ->assertJsonPath('data.evolution.granularite', 'semaine')
            ->assertJsonCount(12, 'data.evolution.points');
    }

    #[Test]
    public function a_manager_can_list_reject_motifs(): void
    {
        EnrollmentRejectMotif::query()->create([
            'title' => 'Doublon d\'enrôlement',
            'description' => 'Déjà enrôlé',
        ]);

        Sanctum::actingAs($this->manager);

        $this->getJson($this->api('/management/enrollment-reject-motifs'))
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Doublon d\'enrôlement');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function enrollment(EnrollmentStatus $status, string $type, array $overrides = []): EnrollmentRequest
    {
        return EnrollmentRequest::query()->create(array_merge([
            'email' => 'demande@example.com',
            'phonenumber' => '+2290162405472',
            'status' => $status->value,
            'type' => $type,
        ], $overrides));
    }
}
