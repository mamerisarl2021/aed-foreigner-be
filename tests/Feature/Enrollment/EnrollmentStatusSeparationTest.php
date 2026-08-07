<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\AgentAvis;
use App\Enums\EnrollmentStatus;
use App\Models\EnrollmentRejectMotif;
use App\Models\EnrollmentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Un niveau ne voit un verdict que s'il est le sien ou s'il est définitif.
 *
 * Régression d'origine : l'agent approuvait une demande et le responsable la
 * découvrait « approuvée » alors qu'il ne l'avait ni prise en charge ni tranchée.
 */
final class EnrollmentStatusSeparationTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $responsable;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();

        foreach ([config('roles.agent'), config('roles.responsable_de_validation')] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->agent = User::factory()->create(['email' => 'agent-sep@example.com']);
        $this->agent->assignRole(config('roles.agent'));

        $this->responsable = User::factory()->create(['email' => 'responsable-sep@example.com']);
        $this->responsable->assignRole(config('roles.responsable_de_validation'));
    }

    #[Test]
    public function la_prise_en_charge_agent_est_visible_dans_le_statut(): void
    {
        $enrollment = $this->createEnrollment();

        Sanctum::actingAs($this->agent);

        $this->patchJson($this->api("/enrolements/{$enrollment->id}/prise-en-charge"))
            ->assertOk()
            ->assertJsonPath('data.statut', EnrollmentStatus::EnCoursAgent->value)
            ->assertJsonPath('data.statut_libelle', 'En cours d\'instruction');

        $this->assertSame(EnrollmentStatus::EnCoursAgent, $enrollment->fresh()->status);
    }

    #[Test]
    public function un_avis_favorable_ne_rend_la_demande_approuvee_pour_personne(): void
    {
        $enrollment = $this->createEnrollment([
            'status' => EnrollmentStatus::EnCoursAgent->value,
            'assigned_agent_id' => $this->agent->id,
        ]);

        Sanctum::actingAs($this->agent);

        $this->patchJson($this->api("/enrolements/{$enrollment->id}/instruction"), [
            'avis' => AgentAvis::Favorable->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.statut', EnrollmentStatus::EnAttenteResponsable->value)
            ->assertJsonPath('data.avis_agent', AgentAvis::Favorable->value);

        $enrollment->refresh();
        $this->assertSame(EnrollmentStatus::EnAttenteResponsable, $enrollment->status);
        $this->assertSame(AgentAvis::Favorable, $enrollment->agent_avis);

        // L'agent, lui, ne lit aucun verdict : le dossier est parti chez le responsable.
        $this->getJson($this->api("/enrolements/{$enrollment->id}"))
            ->assertOk()
            ->assertJsonPath('data.statut_libelle', 'Transmise au responsable');
    }

    #[Test]
    public function le_responsable_voit_a_valider_et_non_approuvee_avant_toute_action(): void
    {
        $enrollment = $this->createEnrollment([
            'status' => EnrollmentStatus::EnAttenteResponsable->value,
            'assigned_agent_id' => $this->agent->id,
            'agent_avis' => AgentAvis::Favorable->value,
            'agent_decided_at' => now(),
        ]);

        Sanctum::actingAs($this->responsable);

        $this->getJson($this->api("/enrolements/{$enrollment->id}"))
            ->assertOk()
            ->assertJsonPath('data.statut', EnrollmentStatus::EnAttenteResponsable->value)
            ->assertJsonPath('data.statut_libelle', 'À valider')
            ->assertJsonPath('data.peut_prendre_en_charge', true)
            ->assertJsonPath('data.peut_valider', false)
            // L'avis de l'agent reste consultable, mais nommé pour ce qu'il est.
            ->assertJsonPath('data.decision_agent.avis', AgentAvis::Favorable->value)
            ->assertJsonPath('data.decision_agent.avis_libelle', 'Favorable');

        $this->getJson($this->api('/enrolements'))
            ->assertOk()
            ->assertJsonPath('data.data.0.statut_libelle', 'À valider')
            ->assertJsonPath('data.data.0.avis_agent', AgentAvis::Favorable->value);
    }

    #[Test]
    public function le_responsable_ne_peut_pas_trancher_sans_prise_en_charge(): void
    {
        $enrollment = $this->createEnrollment([
            'status' => EnrollmentStatus::EnAttenteResponsable->value,
            'assigned_agent_id' => $this->agent->id,
            'agent_avis' => AgentAvis::Favorable->value,
        ]);

        Sanctum::actingAs($this->responsable);

        $this->patchJson($this->api("/enrolements/{$enrollment->id}/validation"), [
            'decision' => 'APPROUVEE',
        ])->assertForbidden();

        $this->assertSame(EnrollmentStatus::EnAttenteResponsable, $enrollment->fresh()->status);
    }

    #[Test]
    public function la_prise_en_charge_responsable_bascule_en_cours_de_validation(): void
    {
        $enrollment = $this->createEnrollment([
            'status' => EnrollmentStatus::EnAttenteResponsable->value,
            'assigned_agent_id' => $this->agent->id,
            'agent_avis' => AgentAvis::Favorable->value,
        ]);

        Sanctum::actingAs($this->responsable);

        $this->patchJson($this->api("/enrolements/{$enrollment->id}/prise-en-charge-validation"))
            ->assertOk()
            ->assertJsonPath('data.statut', EnrollmentStatus::EnCoursResponsable->value)
            ->assertJsonPath('data.statut_libelle', 'En cours de validation')
            ->assertJsonPath('data.peut_valider', true);
    }

    #[Test]
    public function un_avis_defavorable_ne_peut_pas_etre_approuve_directement(): void
    {
        $enrollment = $this->createEnrollment([
            'status' => EnrollmentStatus::EnCoursResponsable->value,
            'assigned_agent_id' => $this->agent->id,
            'assigned_responsable_id' => $this->responsable->id,
            'agent_avis' => AgentAvis::Defavorable->value,
        ]);

        Sanctum::actingAs($this->responsable);

        $this->patchJson($this->api("/enrolements/{$enrollment->id}/validation"), [
            'decision' => 'APPROUVEE',
        ])->assertStatus(422);
    }

    #[Test]
    public function le_retour_agent_annule_l_avis_rendu(): void
    {
        $motif = EnrollmentRejectMotif::query()->create([
            'title' => 'Pièce illisible',
            'description' => 'Le document fourni n\'est pas exploitable.',
        ]);

        $enrollment = $this->createEnrollment([
            'status' => EnrollmentStatus::EnCoursResponsable->value,
            'assigned_agent_id' => $this->agent->id,
            'assigned_responsable_id' => $this->responsable->id,
            'agent_avis' => AgentAvis::Defavorable->value,
            'agent_decided_at' => now(),
            'reject_reasons' => [$motif->id],
        ]);

        Sanctum::actingAs($this->responsable);

        $this->patchJson($this->api("/enrolements/{$enrollment->id}/validation"), [
            'decision' => 'RETOUR_AGENT',
            'motif' => [$motif->id],
            'commentaire' => 'À reprendre.',
        ])->assertOk();

        $enrollment->refresh();
        $this->assertSame(EnrollmentStatus::EnAttenteAgent, $enrollment->status);
        $this->assertNull($enrollment->agent_avis);
        $this->assertNull($enrollment->agent_decided_at);
        $this->assertNull($enrollment->assigned_agent_id);
    }

    #[Test]
    public function le_bloc_decision_agent_reste_vide_tant_qu_aucun_avis_n_est_rendu(): void
    {
        $enrollment = $this->createEnrollment([
            'status' => EnrollmentStatus::EnCoursAgent->value,
            'assigned_agent_id' => $this->agent->id,
        ]);

        Sanctum::actingAs($this->responsable);

        $this->getJson($this->api("/enrolements/{$enrollment->id}"))
            ->assertOk()
            ->assertJsonPath('data.decision_agent.avis', null)
            ->assertJsonPath('data.decision_agent.avis_libelle', null)
            ->assertJsonPath('data.statut_libelle', 'En instruction');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createEnrollment(array $attributes = []): EnrollmentRequest
    {
        return EnrollmentRequest::query()->create(array_merge([
            'email' => 'applicant-sep@example.com',
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::EnAttenteAgent->value,
            'type' => 'PERSONNE_PHYSIQUE',
            'kyc_data' => ['name' => 'KOTO', 'first_name' => 'Ada'],
        ], $attributes));
    }
}
