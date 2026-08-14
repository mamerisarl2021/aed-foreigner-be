<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\AgentAvis;
use App\Enums\EnrollmentStatus;
use App\Models\EnrolledCompany;
use App\Models\EnrollmentRejectMotif;
use App\Models\EnrollmentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class EnrollmentTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['consul.keycloak.enabled' => false]);
        Cache::flush();

        Role::firstOrCreate(['name' => config('roles.client'), 'guard_name' => 'web']);

        $this->client = User::factory()->create([
            'email' => 'demandeur-morale@example.com',
            'name' => 'KOTO',
            'first_name' => 'Ada',
        ]);
        $this->client->assignRole(config('roles.client'));
    }

    #[Test]
    public function physique_applicant_can_track_with_numero_suivi_and_email(): void
    {
        $enrollment = $this->createPhysique([
            'status' => EnrollmentStatus::EnCoursResponsable->value,
            'agent_avis' => AgentAvis::Favorable->value,
            'analysis_details' => ['document' => ['status' => 'VALID']],
            'documents' => ['selfie' => 'secret.jpg'],
            'review_comments' => 'interne',
        ]);

        $this->suivi('PKTRACK001', 'ada@example.com')
            ->assertOk()
            ->assertJsonPath('data.numero_suivi', 'PKTRACK001')
            ->assertJsonPath('data.id', $enrollment->id)
            ->assertJsonPath('data.type', 'PERSONNE_PHYSIQUE')
            ->assertJsonPath('data.statut', EnrollmentStatus::EnCoursResponsable->value)
            ->assertJsonPath('data.statut_libelle', 'En cours de traitement')
            ->assertJsonPath('data.finalisation_disponible', false)
            ->assertJsonPath('data.demandeur.nom', 'KOTO')
            ->assertJsonPath('data.demandeur.prenom', 'Ada')
            ->assertJsonPath('data.motifs', null)
            ->assertJsonMissingPath('data.analyse_kyc')
            ->assertJsonMissingPath('data.avis_agent')
            ->assertJsonMissingPath('data.pieces_jointes')
            ->assertJsonMissingPath('data.review_comments');
    }

    #[Test]
    public function unknown_or_mismatched_credentials_return_the_same_404(): void
    {
        $this->createPhysique();

        $unknown = $this->suivi('PKDOESNOTEX', 'ada@example.com')
            ->assertNotFound()
            ->assertJsonPath('message', 'Demande introuvable.');

        $wrongEmail = $this->suivi('PKTRACK001', 'other@example.com')
            ->assertNotFound()
            ->assertJsonPath('message', 'Demande introuvable.');

        $this->assertSame($unknown->json('message'), $wrongEmail->json('message'));
    }

    #[Test]
    public function missing_body_returns_422(): void
    {
        $this->postJson($this->api('/enrolements/suivi'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Format de donnée invalide.');
    }

    #[Test]
    public function approved_physique_signals_finalisation_and_definitive_label(): void
    {
        $this->createPhysique(['status' => EnrollmentStatus::Approuvee->value]);

        $this->suivi('PKTRACK001', 'ADA@example.com')
            ->assertOk()
            ->assertJsonPath('data.statut_libelle', 'Approuvée')
            ->assertJsonPath('data.finalisation_disponible', true);
    }

    #[Test]
    public function rejected_physique_exposes_motif_titles_not_raw_ids(): void
    {
        $motif = EnrollmentRejectMotif::query()->create([
            'title' => 'Pièce illisible',
            'description' => 'Le document fourni n\'est pas exploitable.',
        ]);

        $this->createPhysique([
            'status' => EnrollmentStatus::Rejetee->value,
            'agent_avis' => AgentAvis::Defavorable->value,
            'reject_reasons' => [$motif->id],
        ]);

        $this->suivi('PKTRACK001', 'ada@example.com')
            ->assertOk()
            ->assertJsonPath('data.statut_libelle', 'Rejetée')
            ->assertJsonPath('data.motifs.0.id', $motif->id)
            ->assertJsonPath('data.motifs.0.title', 'Pièce illisible')
            ->assertJsonPath('data.motifs.0.description', 'Le document fourni n\'est pas exploitable.')
            ->assertJsonMissingPath('data.avis_agent');
    }

    #[Test]
    public function morale_can_be_tracked_with_company_or_demandeur_email(): void
    {
        $motif = EnrollmentRejectMotif::query()->create([
            'title' => 'RCCM manquant',
            'description' => 'Joindre l\'extrait RCCM.',
        ]);

        $this->createMorale([
            'status' => EnrollmentStatus::ACorriger->value,
            'correction_deadline_at' => now()->addDays(3),
            'reject_reasons' => [$motif->id],
        ]);

        $this->suivi('PKMORALE01', 'entreprise@example.com')
            ->assertOk()
            ->assertJsonPath('data.type', 'PERSONNE_MORALE')
            ->assertJsonPath('data.raison_sociale', 'TECH SARL')
            ->assertJsonPath('data.statut_libelle', 'À corriger')
            ->assertJsonPath('data.motifs.0.id', $motif->id)
            ->assertJsonPath('data.motifs.0.title', 'RCCM manquant')
            ->assertJsonPath('data.identifiant', null)
            ->assertJsonPath('data.email_verifie', true)
            ->assertJsonPath('data.telephone_verifie', true)
            ->assertJsonPath('data.finalisation_disponible', false);

        $this->suivi('PKMORALE01', 'demandeur-morale@example.com')
            ->assertOk()
            ->assertJsonPath('data.demandeur.nom', 'KOTO');
    }

    #[Test]
    public function approved_morale_exposes_company_identifiant(): void
    {
        $enrollment = $this->createMorale(['status' => EnrollmentStatus::Approuvee->value]);
        EnrolledCompany::query()->create([
            'identifiant' => 'PMTRACK001',
            'enrollment_request_id' => $enrollment->id,
            'manager_user_id' => $this->client->id,
            'legal_name' => 'TECH SARL',
            'country_of_incorporation' => 'Canada',
            'registration_number' => 'RCCM-CA-001',
            'headquarters_address' => 'Cotonou',
            'activity_sector' => 'Services',
            'legal_representative_name' => 'KOTO',
            'legal_representative_first_name' => 'Ada',
            'company_email' => 'entreprise@example.com',
            'status' => EnrolledCompany::STATUS_ACTIVE,
            'approved_at' => now(),
        ]);

        $this->suivi('PKMORALE01', 'entreprise@example.com')
            ->assertOk()
            ->assertJsonPath('data.identifiant', 'PMTRACK001')
            ->assertJsonPath('data.statut_libelle', 'Approuvée')
            ->assertJsonPath('data.finalisation_disponible', false);
    }

    #[Test]
    public function eleventh_post_is_throttled(): void
    {
        $this->createPhysique();

        for ($i = 0; $i < 10; $i++) {
            $this->suivi('PKTRACK001', 'ada@example.com')->assertOk();
        }

        $this->suivi('PKTRACK001', 'ada@example.com')->assertStatus(429);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPhysique(array $attributes = []): EnrollmentRequest
    {
        return EnrollmentRequest::query()->create(array_merge([
            'tracking_code' => 'PKTRACK001',
            'email' => 'ada@example.com',
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::EnAttenteAgent->value,
            'type' => 'PERSONNE_PHYSIQUE',
            'kyc_data' => ['name' => 'KOTO', 'first_name' => 'Ada'],
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createMorale(array $attributes = []): EnrollmentRequest
    {
        return EnrollmentRequest::query()->create(array_merge([
            'tracking_code' => 'PKMORALE01',
            'email' => 'entreprise@example.com',
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::EnAttenteAgent->value,
            'type' => 'PERSONNE_MORALE',
            'submitted_by_user_id' => $this->client->id,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'kyc_data' => [
                'legal_name' => 'TECH SARL',
                'legal_form' => 'SARL',
                'country_of_incorporation' => 'Canada',
                'legal_representative_name' => 'DOE',
                'legal_representative_first_name' => 'Jane',
            ],
        ], $attributes));
    }

    private function suivi(string $numeroSuivi, string $email): TestResponse
    {
        return $this->postJson($this->api('/enrolements/suivi'), [
            'numero_suivi' => $numeroSuivi,
            'email' => $email,
        ]);
    }
}
