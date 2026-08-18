<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\ActivityLogAction;
use App\Enums\AgentAvis;
use App\Enums\EnrollmentStatus;
use App\Enums\NotificationTemplate;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Models\ActivityLog;
use App\Models\EnrolledCompany;
use App\Models\EnrollmentRejectMotif;
use App\Models\EnrollmentRequest;
use App\Models\Identity;
use App\Models\User;
use App\Services\Enrollment\EnrollmentSlaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PersonneMoraleDecisionTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $agent;

    private User $responsable;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        Storage::fake('local');
        Storage::fake('s3');
        config([
            'filesystems.cloud' => 's3',
            'consul.keycloak.enabled' => false,
            'services.regula.mock' => true,
        ]);

        foreach ([
            config('roles.client'),
            config('roles.agent'),
            config('roles.responsable_de_validation'),
            config('roles.demandeur_authentifie'),
        ] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->client = User::factory()->create([
            'email' => 'morale-owner@example.com',
            'phonenumber' => '+2290162405472',
            'status' => 'ACTIVE',
            'name' => 'KOTO',
            'first_name' => 'Ada',
        ]);
        $this->client->assignRole(config('roles.client'));

        $this->agent = User::factory()->create(['email' => 'agent-pm-decision@example.com']);
        $this->agent->assignRole(config('roles.agent'));

        $this->responsable = User::factory()->create(['email' => 'responsable-pm-decision@example.com']);
        $this->responsable->assignRole(config('roles.responsable_de_validation'));
    }

    #[Test]
    public function claim_instruction_and_approve_persist_company_identifiant_and_dual_mail(): void
    {
        $enrollment = $this->createMoraleEnrollment();

        Sanctum::actingAs($this->agent);
        $this->patchJson($this->api("/enrolements/{$enrollment->id}/prise-en-charge"))
            ->assertOk()
            ->assertJsonPath('data.statut', EnrollmentStatus::EnCoursAgent->value);

        $this->patchJson($this->api("/enrolements/{$enrollment->id}/instruction"), [
            'avis' => AgentAvis::Favorable->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.statut', EnrollmentStatus::EnAttenteResponsable->value);

        Sanctum::actingAs($this->responsable);
        $this->patchJson($this->api("/enrolements/{$enrollment->id}/prise-en-charge-validation"))
            ->assertOk()
            ->assertJsonPath('data.statut', EnrollmentStatus::EnCoursResponsable->value);

        $response = $this->patchJson($this->api("/enrolements/{$enrollment->id}/validation"), [
            'decision' => 'APPROUVEE',
        ])->assertOk();

        $identifiant = $response->json('data.identifiant');
        $this->assertIsString($identifiant);
        $this->assertStringStartsWith('PM', $identifiant);
        $response
            ->assertJsonPath('data.statut', EnrollmentStatus::Approuvee->value)
            ->assertJsonPath('data.identifiant', $identifiant);

        $company = EnrolledCompany::query()->where('enrollment_request_id', $enrollment->id)->first();
        $this->assertNotNull($company);
        $this->assertSame($identifiant, $company->identifiant);
        $this->assertSame('TECH SARL INNOV', $company->legal_name);
        $this->assertSame(EnrolledCompany::STATUS_ACTIVE, $company->status);

        $this->assertTrue(Identity::query()
            ->where('user_id', $this->client->id)
            ->where('type', 'PERSONNE_MORALE')
            ->where('status', 'APPROVED')
            ->exists());

        Bus::assertDispatched(SendEmailNotificationJob::class, function (SendEmailNotificationJob $job) use ($identifiant): bool {
            if ($job->notification->template !== NotificationTemplate::MoraleApproved) {
                return false;
            }

            $emails = array_map(
                fn (array $recipient): string => (string) ($recipient['email'] ?? ''),
                $job->notification->recipients
            );

            return in_array($this->client->email, $emails, true)
                && in_array('entreprise@example.com', $emails, true)
                && ($job->notification->variables['identifiant'] ?? null) === $identifiant;
        });

        Sanctum::actingAs($this->client);
        $this->getJson($this->api("/enrolements/morales/{$enrollment->id}"))
            ->assertOk()
            ->assertJsonPath('data.identifiant', $identifiant)
            ->assertJsonPath('data.statut_libelle', 'Approuvée');
    }

    #[Test]
    public function morale_reject_is_correctable_then_returns_to_agent_queue(): void
    {
        $motif = EnrollmentRejectMotif::query()->create([
            'title' => 'Pièce illisible',
            'description' => 'Le RCCM n\'est pas exploitable.',
        ]);

        $enrollment = $this->createMoraleEnrollment([
            'status' => EnrollmentStatus::EnCoursResponsable->value,
            'assigned_agent_id' => $this->agent->id,
            'assigned_responsable_id' => $this->responsable->id,
            'agent_avis' => AgentAvis::Defavorable->value,
            'reject_reasons' => [$motif->id],
        ]);

        Sanctum::actingAs($this->responsable);
        $this->patchJson($this->api("/enrolements/{$enrollment->id}/validation"), [
            'decision' => 'REJET_CONFIRME',
        ])
            ->assertOk()
            ->assertJsonPath('data.statut', EnrollmentStatus::ACorriger->value);

        $enrollment->refresh();
        $this->assertNotNull($enrollment->correction_deadline_at);

        Bus::assertDispatched(SendEmailNotificationJob::class, function (SendEmailNotificationJob $job): bool {
            return $job->notification->template === NotificationTemplate::MoraleCorrectionRequired
                && ($job->notification->recipients[0]['email'] ?? null) === $this->client->email;
        });

        Sanctum::actingAs($this->client);
        $this->put($this->api("/enrolements/morales/{$enrollment->id}"), [
            'legal_name' => 'TECH SARL CORRIGEE',
            'legal_form' => 'SARL',
            'country_of_incorporation' => 'Canada',
            'registration_number' => 'RCCM-CA-002',
            'incorporation_date' => '2024-03-06',
            'headquarters_address' => 'Cotonou',
            'activity_sector' => 'Services',
            'legal_representative_name' => 'KOTO',
            'legal_representative_first_name' => 'Ada',
            'is_legal_representative' => '1',
            'trade_register_extract' => UploadedFile::fake()->create('rccm.pdf', 100, 'application/pdf'),
        ])
            ->assertOk()
            ->assertJsonPath('data.statut', EnrollmentStatus::EnAttenteAgent->value);

        $enrollment->refresh();
        $this->assertSame('TECH SARL CORRIGEE', $enrollment->kyc_data['legal_name'] ?? null);
        $this->assertNull($enrollment->assigned_agent_id);
        $this->assertNull($enrollment->agent_avis);
        $this->assertNull($enrollment->correction_deadline_at);
    }

    #[Test]
    public function correction_timeout_archives_as_rejetee_and_mails_the_agent(): void
    {
        $enrollment = $this->createMoraleEnrollment([
            'status' => EnrollmentStatus::ACorriger->value,
            'assigned_agent_id' => $this->agent->id,
            'correction_deadline_at' => now()->subHour(),
        ]);

        $result = app(EnrollmentSlaService::class)->checkAndNotify();

        $this->assertSame(1, $result['correction_archived']);
        $this->assertSame(EnrollmentStatus::Rejetee, $enrollment->fresh()->status);
        $this->assertTrue(
            ActivityLog::query()
                ->where('action_code', ActivityLogAction::CorrectionMoraleExpiree->label())
                ->where('enrollment_request_id', $enrollment->id)
                ->exists()
        );

        Bus::assertDispatched(SendEmailNotificationJob::class, function (SendEmailNotificationJob $job): bool {
            return $job->notification->template === NotificationTemplate::MoraleCorrectionExpired
                && ($job->notification->recipients[0]['email'] ?? null) === $this->agent->email;
        });
    }

    #[Test]
    public function correction_reminder_is_sent_once_before_the_deadline(): void
    {
        $enrollment = $this->createMoraleEnrollment([
            'status' => EnrollmentStatus::ACorriger->value,
            'assigned_agent_id' => $this->agent->id,
            'correction_deadline_at' => now()->addHours(12),
        ]);

        $first = app(EnrollmentSlaService::class)->checkAndNotify();
        $this->assertSame(1, $first['correction_reminded']);
        $this->assertNotNull($enrollment->fresh()->correction_reminder_sent_at);

        $second = app(EnrollmentSlaService::class)->checkAndNotify();
        $this->assertSame(0, $second['correction_reminded']);

        Bus::assertDispatchedTimes(SendEmailNotificationJob::class, 1);
    }

    #[Test]
    public function correction_put_is_forbidden_for_another_client(): void
    {
        $enrollment = $this->createMoraleEnrollment([
            'status' => EnrollmentStatus::ACorriger->value,
            'correction_deadline_at' => now()->addDays(7),
        ]);

        $other = User::factory()->create([
            'email' => 'other-owner@example.com',
            'phonenumber' => '+2290162405479',
            'status' => 'ACTIVE',
        ]);
        $other->assignRole(config('roles.client'));

        Sanctum::actingAs($other);
        $this->put($this->api("/enrolements/morales/{$enrollment->id}"), $this->correctionPayload())
            ->assertForbidden();

        $this->assertSame(EnrollmentStatus::ACorriger, $enrollment->fresh()->status);
    }

    #[Test]
    public function correction_put_is_forbidden_after_the_deadline(): void
    {
        $enrollment = $this->createMoraleEnrollment([
            'status' => EnrollmentStatus::ACorriger->value,
            'correction_deadline_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($this->client);
        $this->put($this->api("/enrolements/morales/{$enrollment->id}"), $this->correctionPayload())
            ->assertForbidden();

        $this->assertSame(EnrollmentStatus::ACorriger, $enrollment->fresh()->status);
    }

    #[Test]
    public function correction_put_is_forbidden_on_a_physique_enrollment(): void
    {
        $enrollment = EnrollmentRequest::query()->create([
            'email' => 'physique-correct@example.com',
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::ACorriger->value,
            'type' => 'PERSONNE_PHYSIQUE',
            'submitted_by_user_id' => $this->client->id,
            'correction_deadline_at' => now()->addDays(7),
            'kyc_data' => ['name' => 'KOTO', 'first_name' => 'Ada'],
        ]);

        Sanctum::actingAs($this->client);
        $this->put($this->api("/enrolements/morales/{$enrollment->id}"), $this->correctionPayload())
            ->assertForbidden();
    }

    #[Test]
    public function sla_archive_does_not_clobber_a_corrected_dossier(): void
    {
        $enrollment = $this->createMoraleEnrollment([
            'status' => EnrollmentStatus::EnAttenteAgent->value,
            'correction_deadline_at' => now()->subHour(),
        ]);

        $archived = app(EnrollmentSlaService::class)->archiveExpiredCorrectionIfPending($enrollment);

        $this->assertFalse($archived);
        $this->assertSame(EnrollmentStatus::EnAttenteAgent, $enrollment->fresh()->status);
        $this->assertFalse(
            ActivityLog::query()
                ->where('action_code', ActivityLogAction::CorrectionMoraleExpiree->label())
                ->where('enrollment_request_id', $enrollment->id)
                ->exists()
        );
    }

    #[Test]
    public function physique_rejet_confirme_stays_immediately_rejetee(): void
    {
        $enrollment = EnrollmentRequest::query()->create([
            'email' => 'physique-reject@example.com',
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::EnCoursResponsable->value,
            'type' => 'PERSONNE_PHYSIQUE',
            'assigned_agent_id' => $this->agent->id,
            'assigned_responsable_id' => $this->responsable->id,
            'agent_avis' => AgentAvis::Defavorable->value,
            'kyc_data' => ['name' => 'KOTO', 'first_name' => 'Ada'],
        ]);

        Sanctum::actingAs($this->responsable);
        $this->patchJson($this->api("/enrolements/{$enrollment->id}/validation"), [
            'decision' => 'REJET_CONFIRME',
        ])
            ->assertOk()
            ->assertJsonPath('data.statut', EnrollmentStatus::Rejetee->value);

        $this->assertNull($enrollment->fresh()->correction_deadline_at);
    }

    #[Test]
    public function retour_agent_emails_the_assigned_agent(): void
    {
        $motif = EnrollmentRejectMotif::query()->create([
            'title' => 'Pièce illisible',
            'description' => 'Le document fourni n\'est pas exploitable.',
        ]);

        $enrollment = $this->createMoraleEnrollment([
            'status' => EnrollmentStatus::EnCoursResponsable->value,
            'assigned_agent_id' => $this->agent->id,
            'assigned_responsable_id' => $this->responsable->id,
            'agent_avis' => AgentAvis::Favorable->value,
        ]);

        Sanctum::actingAs($this->responsable);
        $this->patchJson($this->api("/enrolements/{$enrollment->id}/validation"), [
            'decision' => 'RETOUR_AGENT',
            'motif' => [$motif->id],
            'commentaire' => 'À reprendre.',
        ])->assertOk();

        Bus::assertDispatched(SendEmailNotificationJob::class, function (SendEmailNotificationJob $job): bool {
            return $job->notification->template === NotificationTemplate::EnrollmentReturnedToAgent
                && ($job->notification->recipients[0]['email'] ?? null) === $this->agent->email;
        });
    }

    #[Test]
    public function active_enrolled_company_blocks_a_duplicate_submit(): void
    {
        $source = $this->createMoraleEnrollment([
            'status' => EnrollmentStatus::Approuvee->value,
            'email' => 'deja-enrolee@example.com',
        ]);
        EnrolledCompany::query()->create([
            'identifiant' => 'PMDUPLICATE1',
            'enrollment_request_id' => $source->id,
            'manager_user_id' => $this->client->id,
            'legal_name' => 'TECH SARL INNOV',
            'country_of_incorporation' => 'Canada',
            'registration_number' => 'RCCM-CA-001',
            'headquarters_address' => 'Cotonou',
            'activity_sector' => 'Services',
            'legal_representative_name' => 'KOTO',
            'legal_representative_first_name' => 'Ada',
            'company_email' => 'deja-enrolee@example.com',
            'status' => EnrolledCompany::STATUS_ACTIVE,
            'approved_at' => now(),
        ]);

        Identity::query()->create([
            'user_id' => $this->client->id,
            'type' => 'IN_PERSON',
            'level' => 'ADVANCED',
            'status' => 'APPROVED',
            'proof' => ['selfiePath' => ''],
        ]);

        $other = User::factory()->create([
            'email' => 'other-client@example.com',
            'phonenumber' => '+2290162405473',
            'status' => 'ACTIVE',
        ]);
        $other->assignRole(config('roles.client'));
        Identity::query()->create([
            'user_id' => $other->id,
            'type' => 'IN_PERSON',
            'level' => 'ADVANCED',
            'status' => 'APPROVED',
            'proof' => ['selfiePath' => ''],
        ]);

        Sanctum::actingAs($other);
        $this->post($this->api('/kyc/verify'), [
            'email' => $other->email,
            'phonenumber' => $other->phonenumber,
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
            'recto' => UploadedFile::fake()->image('recto.jpg'),
        ])->assertOk();

        $this->post($this->api('/enrolements/morales'), [
            'email' => 'autre-entreprise@example.com',
            'phonenumber' => '+2290162405473',
            'legal_name' => 'AUTRE SARL',
            'country_of_incorporation' => 'Canada',
            'registration_number' => 'RCCM-CA-001',
            'headquarters_address' => 'Cotonou',
            'activity_sector' => 'Services',
            'legal_representative_name' => 'DOE',
            'legal_representative_first_name' => 'Jane',
            'is_legal_representative' => '1',
            'trade_register_extract' => UploadedFile::fake()->create('rccm.pdf', 100, 'application/pdf'),
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
            'recto' => UploadedFile::fake()->image('recto.jpg'),
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Une entreprise correspondant à ces informations est déjà enrôlée.');
    }

    /**
     * @return array<string, mixed>
     */
    private function correctionPayload(): array
    {
        return [
            'legal_name' => 'TECH SARL CORRIGEE',
            'legal_form' => 'SARL',
            'country_of_incorporation' => 'Canada',
            'registration_number' => 'RCCM-CA-002',
            'incorporation_date' => '2024-03-06',
            'headquarters_address' => 'Cotonou',
            'activity_sector' => 'Services',
            'legal_representative_name' => 'KOTO',
            'legal_representative_first_name' => 'Ada',
            'is_legal_representative' => '1',
            'trade_register_extract' => UploadedFile::fake()->create('rccm.pdf', 100, 'application/pdf'),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createMoraleEnrollment(array $attributes = []): EnrollmentRequest
    {
        return EnrollmentRequest::query()->create(array_merge([
            'email' => 'entreprise@example.com',
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::EnAttenteAgent->value,
            'type' => 'PERSONNE_MORALE',
            'tracking_code' => 'PK'.strtoupper(substr(md5(uniqid('', true)), 0, 9)),
            'submitted_by_user_id' => $this->client->id,
            'kyc_data' => [
                'legal_name' => 'TECH SARL INNOV',
                'legal_form' => 'SARL',
                'country_of_incorporation' => 'Canada',
                'registration_number' => 'RCCM-CA-001',
                'incorporation_date' => '2024-03-06',
                'headquarters_address' => 'Cotonou',
                'activity_sector' => 'Services',
                'legal_representative_name' => 'KOTO',
                'legal_representative_first_name' => 'Ada',
                'is_legal_representative' => true,
            ],
            'documents' => [
                'trade_register_extract' => 'docs/rccm.pdf',
                'selfie' => 'selfies/face.jpg',
                'recto' => 'docs/recto.jpg',
            ],
        ], $attributes));
    }
}
