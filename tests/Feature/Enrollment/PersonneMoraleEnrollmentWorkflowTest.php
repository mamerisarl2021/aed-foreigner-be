<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Enums\NotificationTemplate;
use App\Jobs\ForeignerFinalizedJob;
use App\Jobs\MoraleEmailVerificationJob;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Models\EnrolledCompany;
use App\Models\EnrollmentRequest;
use App\Models\Identity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PersonneMoraleEnrollmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filesystems.cloud' => 's3',
            'services.regula.mock' => true,
            'consul.keycloak.enabled' => false,
        ]);
        Storage::fake('s3');
        Storage::fake('local');

        foreach ([
            config('roles.client'),
            config('roles.agent'),
            config('roles.demandeur_authentifie'),
        ] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->client = User::factory()->create([
            'email' => 'morale-client@example.com',
            'phonenumber' => '+2290162405472',
            'status' => 'ACTIVE',
            'name' => 'KOTO',
            'first_name' => 'Ada',
        ]);
        $this->client->assignRole(config('roles.client'));

        Identity::query()->create([
            'user_id' => $this->client->id,
            'type' => 'IN_PERSON',
            'level' => 'ADVANCED',
            'status' => 'APPROVED',
            'proof' => ['selfiePath' => ''],
        ]);

        $this->agent = User::factory()->create(['email' => 'agent-morale@example.com']);
        $this->agent->assignRole(config('roles.agent'));
    }

    #[Test]
    public function submit_is_forbidden_without_an_approved_physique_identity(): void
    {
        Sanctum::actingAs($this->client);
        // Le KYC passe tant que l'identité physique existe : c'est bien le dépôt qui doit fermer.
        $this->passKyc();
        $this->client->identities()->delete();

        $this->post($this->api('/enrolements/morales'), $this->submitPayload())
            ->assertForbidden();
    }

    #[Test]
    public function submit_requires_a_kyc_session(): void
    {
        Sanctum::actingAs($this->client);

        $this->post($this->api('/enrolements/morales'), $this->submitPayload())
            ->assertStatus(400)
            ->assertJsonPath('message', 'Veuillez d\'abord valider le KYC.');
    }

    #[Test]
    public function submit_returns_a_tracking_code_and_does_not_send_confirmation_yet(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->client);
        $this->passKyc();

        $this->post($this->api('/enrolements/morales'), $this->submitPayload())
            ->assertOk()
            ->assertJsonPath('data.statut', EnrollmentStatus::AwaitingContactVerification->value);

        $enrollment = EnrollmentRequest::query()->where('type', 'PERSONNE_MORALE')->first();
        $this->assertNotNull($enrollment);
        $this->assertNotNull($enrollment->tracking_code);
        $this->assertStringStartsWith('PK', (string) $enrollment->tracking_code);

        Bus::assertDispatched(MoraleEmailVerificationJob::class);
        Bus::assertNotDispatched(ForeignerFinalizedJob::class);
        Bus::assertNotDispatched(SendEmailNotificationJob::class);
    }

    #[Test]
    public function document_kyc_step_needs_no_selfie_and_stores_no_face_score(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->client);

        $this->post($this->api('/kyc/document/verify'), [
            'recto' => UploadedFile::fake()->image('recto.jpg'),
        ])
            ->assertOk()
            ->assertJsonPath('data.kyc_valid', true)
            ->assertJsonPath('data.similarity', null);

        $this->post($this->api('/enrolements/morales'), $this->submitPayload())->assertOk();

        $enrollment = EnrollmentRequest::query()->where('type', 'PERSONNE_MORALE')->first();
        $this->assertNotNull($enrollment);
        $this->assertNull($enrollment->selfie_captured_at);
        $this->assertNull($enrollment->similarity);
        $this->assertNull($enrollment->liveness);
        $this->assertArrayNotHasKey('selfie', (array) $enrollment->documents);
        $this->assertTrue(($enrollment->analysis_details['document_only'] ?? false) === true);
    }

    #[Test]
    public function document_kyc_step_is_refused_without_an_approved_physique_identity(): void
    {
        $this->client->identities()->delete();
        Sanctum::actingAs($this->client);

        $this->post($this->api('/kyc/document/verify'), [
            'recto' => UploadedFile::fake()->image('recto.jpg'),
        ])->assertForbidden();
    }

    #[Test]
    public function supporting_documents_must_be_pdf_files(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->client);
        $this->passKyc();

        $payload = $this->submitPayload();
        $payload['trade_register_extract'] = UploadedFile::fake()->image('rccm.jpg');

        $this->post($this->api('/enrolements/morales'), $payload)
            ->assertStatus(422)
            ->assertJsonPath(
                'data.trade_register_extract.0',
                'L\'extrait du registre de commerce doit être un fichier PDF.'
            );
    }

    #[Test]
    public function supporting_documents_are_capped_at_five_megabytes(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->client);
        $this->passKyc();

        $payload = $this->submitPayload();
        $payload['statutes'] = UploadedFile::fake()->create('statuts.pdf', 5121, 'application/pdf');

        $this->post($this->api('/enrolements/morales'), $payload)
            ->assertStatus(422)
            ->assertJsonPath('data.statutes.0', 'Les statuts ne doivent pas dépasser 5 Mo.');
    }

    #[Test]
    public function email_then_phone_otp_promote_the_request_and_email_the_demandeur(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->client);
        $this->passKyc();

        $submit = $this->post($this->api('/enrolements/morales'), $this->submitPayload());
        $submit->assertOk();
        $demandeId = $submit->json('data.demande_id');
        $numeroSuivi = $submit->json('data.numero_suivi');
        $this->assertIsString($demandeId);
        $this->assertIsString($numeroSuivi);
        $this->assertStringStartsWith('PK', $numeroSuivi);

        $token = null;
        Bus::assertDispatched(MoraleEmailVerificationJob::class, function (MoraleEmailVerificationJob $job) use (&$token): bool {
            if (preg_match('#/morale/verify-email/[^/]+/([^/]+)$#', $job->verificationLink, $matches) === 1) {
                $token = $matches[1];
            }

            return true;
        });
        $this->assertIsString($token);

        $this->postJson($this->api("/enrolements/morales/{$demandeId}/verify-email"), [
            'token' => $token,
        ])->assertOk();

        Bus::assertNotDispatched(SendEmailNotificationJob::class);

        $this->postJson($this->api("/enrolements/morales/{$demandeId}/send-phone-otp"))
            ->assertOk();

        Cache::put("morale_otp_phone_{$demandeId}", hash('sha256', '111222'), 300);
        Cache::forget("morale_otp_phone_attempts_{$demandeId}");

        $this->postJson($this->api("/enrolements/morales/{$demandeId}/verify-phone-otp"), [
            'otp' => '111222',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', EnrollmentStatus::EnAttenteAgent->value);

        Bus::assertDispatched(SendEmailNotificationJob::class, function (SendEmailNotificationJob $job) use ($numeroSuivi): bool {
            return $job->notification->template === NotificationTemplate::ForeignerFinalized
                && ($job->notification->variables['numero_suivi'] ?? null) === $numeroSuivi
                && ($job->notification->recipients[0]['email'] ?? null) === $this->client->email;
        });
    }

    #[Test]
    public function owner_can_list_and_show_company_fields_and_attachments(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->client);
        $this->passKyc();

        $demandeId = $this->post($this->api('/enrolements/morales'), $this->submitPayload())
            ->assertOk()
            ->json('data.demande_id');

        $this->getJson($this->api('/enrolements/morales'))
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $demandeId)
            ->assertJsonPath('data.data.0.raison_sociale', 'TECH SARL INNOV')
            ->assertJsonPath('data.data.0.forme_juridique', 'SARL')
            ->assertJsonPath('data.data.0.pays_origine', 'Canada')
            ->assertJsonPath('data.data.0.email_verifie', false);

        $this->getJson($this->api("/enrolements/morales/{$demandeId}"))
            ->assertOk()
            ->assertJsonPath('data.informations_entreprise.raison_sociale', 'TECH SARL INNOV')
            ->assertJsonPath('data.informations_entreprise.secteur_activite', 'Services')
            ->assertJsonPath('data.informations_entreprise.numero_immatriculation_legal', 'RCCM-CA-001')
            ->assertJsonPath('data.email_verifie', false)
            ->assertJsonPath('data.pieces_jointes.0.type', 'trade_register_extract');
    }

    #[Test]
    public function duplicate_enrolled_company_is_conflict(): void
    {
        EnrollmentRequest::query()->create([
            'email' => 'deja@example.com',
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::Approuvee->value,
            'type' => 'PERSONNE_MORALE',
            'kyc_data' => [
                'legal_name' => 'OTHER',
                'registration_number' => 'RCCM-CA-001',
                'country_of_incorporation' => 'Canada',
            ],
        ]);

        Sanctum::actingAs($this->client);
        $this->passKyc();

        $this->post($this->api('/enrolements/morales'), $this->submitPayload())
            ->assertStatus(409)
            ->assertJsonPath('message', 'Une entreprise correspondant à ces informations est déjà enrôlée.');
    }

    #[Test]
    public function agent_detail_exposes_analyse_kyc_without_using_company_legal_name(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->client);
        $this->passKyc();

        $demandeId = $this->post($this->api('/enrolements/morales'), $this->submitPayload())
            ->json('data.demande_id');

        $enrollment = EnrollmentRequest::query()->findOrFail($demandeId);
        $enrollment->status = EnrollmentStatus::EnAttenteAgent;
        $enrollment->email_verified_at = now();
        $enrollment->phone_verified_at = now();
        $enrollment->save();

        Sanctum::actingAs($this->agent);

        $this->getJson($this->api("/enrolements/{$demandeId}"))
            ->assertOk()
            ->assertJsonPath('data.informations_entreprise.raison_sociale', 'TECH SARL INNOV')
            ->assertJsonPath('data.analyse_kyc.document_identite.nom', null)
            ->assertJsonPath('data.informations_entreprise.secteur_activite', 'Services');
    }

    #[Test]
    public function morale_detail_exposes_similar_enrollments_for_cross_check(): void
    {
        Role::firstOrCreate(['name' => config('roles.responsable_de_validation'), 'guard_name' => 'web']);
        $responsable = User::factory()->create(['email' => 'responsable-pm-similar@example.com']);
        $responsable->assignRole(config('roles.responsable_de_validation'));

        $kyc = [
            'legal_name' => 'TECH SARL INNOV',
            'registration_number' => 'RCCM-CA-001',
            'country_of_incorporation' => 'Canada',
        ];

        $enrollment = EnrollmentRequest::query()->create([
            'email' => 'entreprise-a@example.com',
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::EnAttenteAgent->value,
            'type' => 'PERSONNE_MORALE',
            'tracking_code' => 'PKSIMILAR01',
            'kyc_data' => $kyc,
            'submitted_by_user_id' => $this->client->id,
        ]);

        $other = EnrollmentRequest::query()->create([
            'email' => 'entreprise-b@example.com',
            'phonenumber' => '+2290162405473',
            'status' => EnrollmentStatus::EnAttenteAgent->value,
            'type' => 'PERSONNE_MORALE',
            'tracking_code' => 'PKSIMILAR02',
            'kyc_data' => $kyc,
        ]);

        Sanctum::actingAs($this->agent);

        $this->getJson($this->api("/enrolements/{$enrollment->id}"))
            ->assertOk()
            ->assertJsonPath('data.similar_enrollments.0.type', 'enrollment_request')
            ->assertJsonPath('data.similar_enrollments.0.enrollment_request_id', $other->id)
            ->assertJsonPath('data.similar_enrollments.0.legal_name', 'TECH SARL INNOV');

        $enrollment->status = EnrollmentStatus::EnAttenteResponsable;
        $enrollment->save();

        Sanctum::actingAs($responsable);

        $this->getJson($this->api("/enrolements/{$enrollment->id}"))
            ->assertOk()
            ->assertJsonPath('data.similar_enrollments.0.enrollment_request_id', $other->id)
            ->assertJsonPath('data.numero_suivi', 'PKSIMILAR01');
    }

    #[Test]
    public function a_second_company_can_be_submitted_after_an_approved_one(): void
    {
        $source = EnrollmentRequest::query()->create([
            'email' => 'deja-approuvee@example.com',
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::Approuvee->value,
            'type' => 'PERSONNE_MORALE',
            'tracking_code' => 'PKAPPROVED1',
            'submitted_by_user_id' => $this->client->id,
            'kyc_data' => [
                'legal_name' => 'TECH SARL INNOV',
                'country_of_incorporation' => 'Canada',
                'registration_number' => 'RCCM-CA-001',
            ],
        ]);
        EnrolledCompany::query()->create([
            'identifiant' => 'PMAPPROVED1',
            'enrollment_request_id' => $source->id,
            'manager_user_id' => $this->client->id,
            'legal_name' => 'TECH SARL INNOV',
            'country_of_incorporation' => 'Canada',
            'registration_number' => 'RCCM-CA-001',
            'headquarters_address' => 'Cotonou',
            'activity_sector' => 'Services',
            'legal_representative_name' => 'KOTO',
            'legal_representative_first_name' => 'Ada',
            'company_email' => 'deja-approuvee@example.com',
            'status' => EnrolledCompany::STATUS_ACTIVE,
            'approved_at' => now(),
        ]);

        Sanctum::actingAs($this->client);
        $this->passKyc();

        $payload = $this->submitPayload();
        $payload['legal_name'] = 'AUTRE SARL';
        $payload['registration_number'] = 'RCCM-CA-999';
        $payload['email'] = 'autre-societe@example.com';

        $this->post($this->api('/enrolements/morales'), $payload)
            ->assertOk()
            ->assertJsonPath('data.statut', EnrollmentStatus::AwaitingContactVerification->value);

        $this->assertSame(2, EnrollmentRequest::query()
            ->where('type', 'PERSONNE_MORALE')
            ->where('submitted_by_user_id', $this->client->id)
            ->count());
    }

    private function passKyc(): void
    {
        $this->post($this->api('/kyc/document/verify'), [
            'recto' => UploadedFile::fake()->image('recto.jpg'),
        ])->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function submitPayload(): array
    {
        return [
            'email' => 'entreprise@example.com',
            'phonenumber' => '+2290162405472',
            'legal_name' => 'TECH SARL INNOV',
            'legal_form' => 'SARL',
            'country_of_incorporation' => 'Canada',
            'registration_number' => 'RCCM-CA-001',
            'incorporation_date' => '2024-03-06',
            'headquarters_address' => 'Cotonou',
            'activity_sector' => 'Services',
            'legal_representative_name' => 'KOTO',
            'legal_representative_first_name' => 'Ada',
            'is_legal_representative' => '1',
            'trade_register_extract' => UploadedFile::fake()->create('rccm.pdf', 100, 'application/pdf'),
            'recto' => UploadedFile::fake()->image('recto.jpg'),
        ];
    }
}
