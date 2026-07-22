<?php

namespace Tests\Feature;

use App\Jobs\ForeignerFinalizedJob;
use App\Jobs\ForeignerOtpJob;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Jobs\RegulaAnalysisJob;
use App\Jobs\SendSmsJob;
use App\Jobs\UploadEnrollmentFilesJob;
use App\Jobs\WelcomeUserJob;
use App\Models\EnrollmentRequest;
use App\Models\Identity;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\PKI\TrustedXClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PDF-faithful Personne Physique enrollment workflow.
 *
 * OTP → enroll → agent claim/visio/approve|propose-reject →
 * responsable approve|confirm-reject|return → finalization (TrustedX) → manager stats.
 */
class PersonnePhysiqueEnrollmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $supervisor;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filesystems.cloud', 's3');
        Storage::fake('s3');
        Storage::fake('local');
        Bus::fake();

        foreach ([
            'agent',
            'responsable_de_validation',
            'manager',
            'administrateur_plateforme',
            'client',
            'demandeur_authentifie',
            'auditeur',
        ] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->seed(\Database\Seeders\EnrollmentRejectMotifSeeder::class);

        $this->agent = User::factory()->create(['status' => 'ACTIVE']);
        $this->agent->assignRole('agent');

        $this->supervisor = User::factory()->create(['status' => 'ACTIVE']);
        $this->supervisor->assignRole('responsable_de_validation');

        $this->manager = User::factory()->create(['status' => 'ACTIVE']);
        $this->manager->assignRole('manager');
    }

    #[Test]
    public function happy_path_supervisor_approve_creates_created_user_without_trustedx_register(): void
    {
        $email = 'foreigner.happy@example.com';
        $phone = '+22990111222';

        $this->submitVerifiedEnrollment($email, $phone);

        $enrollment = EnrollmentRequest::query()->where('email', $email)->firstOrFail();
        $this->assertSame('PENDING', $enrollment->status);
        $this->assertSame('PERSONNE_PHYSIQUE', $enrollment->type);

        Bus::assertChained([
            UploadEnrollmentFilesJob::class,
            RegulaAnalysisJob::class,
            ForeignerFinalizedJob::class,
        ]);

        Sanctum::actingAs($this->agent);
        $this->postJson($this->api("/management/identity-reviews/{$enrollment->id}/claim"))->assertOk();
        $this->postJson($this->api("/management/identity-reviews/{$enrollment->id}/approve"))->assertOk();
        $this->assertSame('APPROVED_BY_AGENT', $enrollment->fresh()->status);

        // TrustedX must NOT be called on supervisor approve (PDF §4: after finalization)
        $this->partialMock(TrustedXClientService::class, function ($mock) {
            $mock->shouldReceive('register')->never();
        });

        Sanctum::actingAs($this->supervisor);
        $this->postJson($this->api("/management/identity-reviews/{$enrollment->id}/supervisor/approve"))
            ->assertOk()
            ->assertJsonStructure(['data' => ['user_id', 'npi']]);

        $enrollment->refresh();
        $this->assertSame('APPROVED', $enrollment->status);

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame('CREATED', $user->status);
        $this->assertNull($user->trustedx_registered_at);
        $this->assertSame('M', $user->sexe);
        $this->assertTrue($user->hasRole('client'));
        $this->assertStringStartsWith('F-', $user->npi);

        $identity = Identity::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('APPROVED', $identity->status);

        $this->assertNotNull(DB::table('password_resets')->where(['npi' => $user->npi, 'type' => 'all'])->first());
        Bus::assertDispatched(WelcomeUserJob::class);
    }

    #[Test]
    public function finalization_registers_trustedx_activates_user_and_finalizes_enrollment(): void
    {
        $email = 'foreigner.finalize@example.com';
        $enrollmentId = $this->submitVerifiedEnrollment($email, '+22990111333');

        Sanctum::actingAs($this->agent);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/claim"))->assertOk();
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/approve"))->assertOk();

        Sanctum::actingAs($this->supervisor);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/supervisor/approve"))->assertOk();

        $user = User::query()->where('email', $email)->firstOrFail();
        $token = PasswordResetToken::where('npi', $user->npi)->where('type', 'all')->value('token');
        $this->assertNotNull($token);

        $this->mock(TrustedXClientService::class, function ($mock) {
            $mock->shouldReceive('register')->once()->andReturn([
                'status' => true,
                'data' => ['id' => 'tx-user-1'],
                'has_user' => false,
            ]);
            $mock->shouldReceive('setDefaultPassword')->twice()->andReturn([
                'status' => true,
                'data' => [],
            ]);
            $mock->shouldReceive('getUserWithNPI')->andReturn([
                'status' => true,
                'data' => ['id' => 'tx-user-1', 'npi' => 'F-00000001'],
            ]);
        });

        $this->postJson($this->api('/clients/all/reset'), [
            'token' => $token,
            'npi' => $user->npi,
            'password' => 'SecurePass1!',
            'pin' => '123456',
            'security_questions' => [
                ['question' => 'Ville de naissance ?', 'answer' => 'Paris'],
            ],
        ])->assertOk();

        $user->refresh();
        $this->assertSame('ACTIVE', $user->status);
        $this->assertNotNull($user->trustedx_registered_at);
        $this->assertSame('Paris', $user->security_questions[0]['answer'] ?? null);
        $this->assertSame('FINALIZED', EnrollmentRequest::query()->findOrFail($enrollmentId)->status);
    }

    #[Test]
    public function agent_reject_proposes_rejected_by_agent_without_applicant_email(): void
    {
        $email = 'foreigner.reject.agent@example.com';
        $enrollmentId = $this->submitVerifiedEnrollment($email, '+22990333444');

        Sanctum::actingAs($this->agent);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/claim"))->assertOk();

        Bus::fake([SendEmailNotificationJob::class]);

        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/reject"), [
            'stage' => 'KYC',
            'reasons' => ['doc_invalid', 'photo_mismatch'],
            'comments' => 'Pièce non conforme',
        ])->assertOk();

        $enrollment = EnrollmentRequest::query()->findOrFail($enrollmentId);
        $this->assertSame('REJECTED_BY_AGENT', $enrollment->status);
        $this->assertSame(['doc_invalid', 'photo_mismatch'], $enrollment->reject_reasons);
        $this->assertNull(User::query()->where('email', $email)->first());
        Bus::assertNotDispatched(SendEmailNotificationJob::class);
    }

    #[Test]
    public function supervisor_confirms_agent_rejection_and_notifies_applicant(): void
    {
        $email = 'foreigner.reject.confirm@example.com';
        $enrollmentId = $this->submitVerifiedEnrollment($email, '+22990555666');

        Sanctum::actingAs($this->agent);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/claim"))->assertOk();
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/reject"), [
            'stage' => 'KYC',
            'reasons' => ['insufficient_evidence'],
            'comments' => 'Dossier incomplet',
        ])->assertOk();
        $this->assertSame('REJECTED_BY_AGENT', EnrollmentRequest::query()->findOrFail($enrollmentId)->status);

        Sanctum::actingAs($this->supervisor);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/supervisor/reject"), [
            'stage' => 'KYC',
            'reasons' => ['insufficient_evidence'],
            'comments' => 'Confirmé',
        ])->assertOk();

        $this->assertSame('REJECTED', EnrollmentRequest::query()->findOrFail($enrollmentId)->status);
        Bus::assertDispatched(SendEmailNotificationJob::class);
        $this->assertNull(User::query()->where('email', $email)->first());
    }

    #[Test]
    public function supervisor_cannot_final_reject_from_approved_by_agent(): void
    {
        $enrollmentId = $this->submitVerifiedEnrollment('foreigner.no.finalreject@example.com', '+22990555777');

        Sanctum::actingAs($this->agent);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/claim"))->assertOk();
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/approve"))->assertOk();

        Sanctum::actingAs($this->supervisor);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/supervisor/reject"), [
            'stage' => 'KYC',
            'reasons' => ['insufficient_evidence'],
        ])->assertForbidden();

        $this->assertSame('APPROVED_BY_AGENT', EnrollmentRequest::query()->findOrFail($enrollmentId)->status);
    }

    #[Test]
    public function supervisor_can_return_from_rejected_by_agent(): void
    {
        $enrollmentId = $this->submitVerifiedEnrollment('foreigner.return.reject@example.com', '+22990555888');

        Sanctum::actingAs($this->agent);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/claim"))->assertOk();
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/reject"), [
            'stage' => 'DOCUMENT',
            'reasons' => ['doc_invalid'],
        ])->assertOk();

        Sanctum::actingAs($this->supervisor);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/supervisor/return"), [
            'reasons' => ['doc_invalid'],
            'comments' => 'Revoir la pièce',
        ])->assertOk();

        $this->assertSame('RETURNED_TO_AGENT', EnrollmentRequest::query()->findOrFail($enrollmentId)->status);
    }

    #[Test]
    public function claim_conflicts_when_another_agent_already_owns_the_request(): void
    {
        $enrollmentId = $this->submitVerifiedEnrollment('foreigner.claim@example.com', '+22990777888');

        Sanctum::actingAs($this->agent);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/claim"))->assertOk();

        $otherAgent = User::factory()->create(['status' => 'ACTIVE']);
        $otherAgent->assignRole('agent');
        Sanctum::actingAs($otherAgent);

        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/claim"))
            ->assertStatus(409);
    }

    #[Test]
    public function agent_cannot_approve_without_claiming_first(): void
    {
        $enrollmentId = $this->submitVerifiedEnrollment('foreigner.noclaim@example.com', '+22990888999');

        Sanctum::actingAs($this->agent);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/approve"))
            ->assertForbidden();
    }

    #[Test]
    public function list_filters_enrollment_requests_by_status_assignment_and_search(): void
    {
        $pendingId = $this->submitVerifiedEnrollment('filter.pending@example.com', '+22991111000');
        $otherId = $this->submitVerifiedEnrollment('filter.other@example.com', '+22992222000');

        Sanctum::actingAs($this->agent);
        $this->postJson($this->api("/management/identity-reviews/{$pendingId}/claim"))->assertOk();

        $assigned = $this->getJson($this->api('/management/identity-reviews?status=PENDING&assigned=true'))
            ->assertOk()
            ->json('data.data');
        $this->assertCount(1, $assigned);
        $this->assertSame($pendingId, $assigned[0]['id']);

        $unassigned = $this->getJson($this->api('/management/identity-reviews?status=PENDING&assigned=false'))
            ->assertOk()
            ->json('data.data');
        $this->assertTrue(collect($unassigned)->contains(fn ($row) => $row['id'] === $otherId));
    }

    #[Test]
    public function policy_denies_client_access_to_identity_reviews(): void
    {
        $enrollmentId = $this->submitVerifiedEnrollment('policy.client@example.com', '+22993333000');

        $client = User::factory()->create(['status' => 'ACTIVE']);
        $client->assignRole('client');
        Sanctum::actingAs($client);

        $this->getJson($this->api('/management/identity-reviews'))->assertForbidden();
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/claim"))->assertForbidden();
    }

    #[Test]
    public function policy_denies_supervisor_from_claiming_and_agent_from_supervisor_actions(): void
    {
        $enrollmentId = $this->submitVerifiedEnrollment('policy.roles@example.com', '+22994444000');

        Sanctum::actingAs($this->supervisor);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/claim"))
            ->assertForbidden();

        Sanctum::actingAs($this->agent);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/claim"))->assertOk();
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/approve"))->assertOk();

        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/supervisor/approve"))
            ->assertForbidden();
    }

    #[Test]
    public function agent_can_request_and_complete_visio_cycle(): void
    {
        $enrollmentId = $this->submitVerifiedEnrollment('foreigner.visio@example.com', '+22995555000');

        Sanctum::actingAs($this->agent);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/claim"))->assertOk();

        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/visio/request"), [
            'notes' => 'Vérification identité à clarifier',
        ])->assertOk();

        $enrollment = EnrollmentRequest::query()->findOrFail($enrollmentId);
        $this->assertSame('VISIO_REQUESTED', $enrollment->status);

        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/visio/complete"))
            ->assertOk();
        $this->assertSame('PENDING', $enrollment->fresh()->status);
    }

    #[Test]
    public function supervisor_return_allows_agent_re_approve_then_supervisor_approve(): void
    {
        $email = 'foreigner.return@example.com';
        $enrollmentId = $this->submitVerifiedEnrollment($email, '+22996666000');

        Sanctum::actingAs($this->agent);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/claim"))->assertOk();
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/approve"))->assertOk();

        Sanctum::actingAs($this->supervisor);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/supervisor/return"), [
            'reasons' => ['kyc_incomplete'],
            'comments' => 'Compléter l\'adresse',
        ])->assertOk();

        $this->assertSame('RETURNED_TO_AGENT', EnrollmentRequest::query()->findOrFail($enrollmentId)->status);

        Sanctum::actingAs($this->agent);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/approve"))->assertOk();

        Sanctum::actingAs($this->supervisor);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/supervisor/approve"))
            ->assertOk();

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame('CREATED', $user->status);
        $this->assertSame('APPROVED', EnrollmentRequest::query()->findOrFail($enrollmentId)->status);
    }

    #[Test]
    public function reject_with_invalid_motif_returns_422_and_valid_motif_proposes_rejection(): void
    {
        $enrollmentId = $this->submitVerifiedEnrollment('foreigner.motif@example.com', '+22997777000');

        Sanctum::actingAs($this->agent);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/claim"))->assertOk();

        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/reject"), [
            'stage' => 'KYC',
            'reasons' => ['not_a_real_motif'],
        ])->assertStatus(422);

        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/reject"), [
            'stage' => 'DOCUMENT',
            'reasons' => ['doc_invalid'],
        ])->assertOk();

        $this->assertSame('REJECTED_BY_AGENT', EnrollmentRequest::query()->findOrFail($enrollmentId)->status);
    }

    #[Test]
    public function show_includes_similar_enrollments_payload_shape(): void
    {
        $enrollmentId = $this->submitVerifiedEnrollment('foreigner.similar@example.com', '+22998888000');

        Sanctum::actingAs($this->agent);
        $this->getJson($this->api("/management/identity-reviews/{$enrollmentId}"))
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'status', 'similar_enrollments', 'visio_notes', 'sla_deadline_at'],
            ]);
    }

    #[Test]
    public function manager_can_read_enrollment_stats_but_responsable_and_client_cannot(): void
    {
        $this->submitVerifiedEnrollment('stats.one@example.com', '+22990001111');

        Sanctum::actingAs($this->manager);
        $this->getJson($this->api('/management/enrollment-stats'))
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'counts' => ['received', 'in_progress', 'approved', 'rejected', 'by_status'],
                    'average_handling_seconds',
                    'average_handling_hours',
                    'reject_rate_by_motif',
                ],
            ]);

        Sanctum::actingAs($this->supervisor);
        $this->getJson($this->api('/management/enrollment-stats'))->assertForbidden();

        $client = User::factory()->create(['status' => 'ACTIVE']);
        $client->assignRole('client');
        Sanctum::actingAs($client);
        $this->getJson($this->api('/management/enrollment-stats'))->assertForbidden();
    }

    private function submitVerifiedEnrollment(string $email, string $phone): int
    {
        $this->postJson($this->api('/foreigner/send-otp'), ['email' => $email])->assertOk();
        Bus::assertDispatched(ForeignerOtpJob::class);

        $emailOtp = Cache::get('foreigner_otp_'.strtolower($email));
        $this->assertNotNull($emailOtp);
        $this->postJson($this->api('/foreigner/verify-otp'), [
            'email' => $email,
            'otp' => $emailOtp,
        ])->assertOk();

        $this->postJson($this->api('/foreigner/send-otp'), ['phonenumber' => $phone])->assertOk();
        Bus::assertDispatched(SendSmsJob::class);

        $phoneOtp = Cache::get('foreigner_otp_phone_'.$phone);
        $this->assertNotNull($phoneOtp);
        $this->postJson($this->api('/foreigner/verify-otp'), [
            'phonenumber' => $phone,
            'otp' => $phoneOtp,
        ])->assertOk();

        $response = $this->post($this->api('/foreigner/enroll'), [
            'email' => $email,
            'phonenumber' => $phone,
            'name' => 'DOE',
            'first_name' => 'JOHN',
            'sexe' => 'M',
            'date_of_birth' => '1990-01-15',
            'place_of_birth' => 'Paris',
            'nationality' => 'FR',
            'country_of_residence' => 'BJ',
            'address' => 'Cotonou',
            'document_type' => 'PASSPORT',
            'document_number' => 'P1234567',
            'liveness' => 'PASS',
            'similarity' => '0.98',
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
            'recto' => UploadedFile::fake()->image('recto.jpg'),
            'verso' => UploadedFile::fake()->image('verso.jpg'),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['enrollment_request_id']]);

        return (int) $response->json('data.enrollment_request_id');
    }
}
