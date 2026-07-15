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
 * End-to-end Personne Physique enrollment workflow for AED Étranger.
 *
 * Covers the implemented backend path from the official parcours:
 * OTP (email + phone) → submit demande → agent claim/approve|reject →
 * supervisor approve|reject → user/identity/NPI + finalization invitation.
 *
 * Explicitly NOT covered here (not implemented or out of HTTP API scope):
 * - Visio request by agent
 * - SLA alerts / manager dashboard
 * - Similarity scoring against already-approved identities (beyond stored Regula fields)
 * - Frontend finalization page (password / security questions) — only WelcomeUserJob + tokens
 * - Personne morale flow
 */
class PersonnePhysiqueEnrollmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filesystems.cloud', 's3');
        Storage::fake('s3');
        Storage::fake('local');
        Bus::fake();

        foreach (['tech_one', 'tech_two', 'tech_three', 'superviseur', 'client', 'admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->agent = User::factory()->create(['status' => 'ACTIVE']);
        $this->agent->assignRole('tech_one');

        $this->supervisor = User::factory()->create(['status' => 'ACTIVE']);
        $this->supervisor->assignRole('superviseur');
    }

    #[Test]
    public function happy_path_from_otp_to_supervisor_approval_creates_user_identity_and_finalization_invite(): void
    {
        $email = 'foreigner.happy@example.com';
        $phone = '+22990111222';

        $this->submitVerifiedEnrollment($email, $phone);

        $enrollment = EnrollmentRequest::query()->where('email', $email)->firstOrFail();
        $this->assertSame('PENDING', $enrollment->status);
        $this->assertSame('PERSONNE_PHYSIQUE', $enrollment->type);
        $this->assertSame('DOE', $enrollment->kyc_data['name']);
        $this->assertSame('PASSPORT', $enrollment->kyc_data['document_type']);

        Bus::assertChained([
            UploadEnrollmentFilesJob::class,
            RegulaAnalysisJob::class,
            ForeignerFinalizedJob::class,
        ]);

        Sanctum::actingAs($this->agent);

        $this->getJson($this->api('/management/identity-reviews?status=PENDING&type=PERSONNE_PHYSIQUE'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $listIds = collect($this->getJson($this->api('/management/identity-reviews?status=PENDING'))->json('data.data'))
            ->pluck('id')
            ->all();
        $this->assertContains($enrollment->id, $listIds);

        $this->getJson($this->api("/management/identity-reviews/{$enrollment->id}"))
            ->assertOk()
            ->assertJsonPath('data.id', $enrollment->id)
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.type', 'PERSONNE_PHYSIQUE')
            ->assertJsonPath('data.email', $email)
            ->assertJsonPath('data.kyc_data.name', 'DOE');

        $this->postJson($this->api("/management/identity-reviews/{$enrollment->id}/claim"))
            ->assertOk()
            ->assertJsonPath('data.assigned_agent_id', $this->agent->id);

        $this->postJson($this->api("/management/identity-reviews/{$enrollment->id}/approve"))
            ->assertOk()
            ->assertJsonPath('data.enrollment_id', $enrollment->id);

        $enrollment->refresh();
        $this->assertSame('APPROVED_BY_AGENT', $enrollment->status);
        Bus::assertDispatched(SendEmailNotificationJob::class);

        Sanctum::actingAs($this->supervisor);

        $this->partialMock(TrustedXClientService::class, function ($mock) {
            $mock->shouldReceive('register')->once()->andReturn([
                'status' => true,
                'has_user' => false,
            ]);
        });

        $this->postJson($this->api("/management/identity-reviews/{$enrollment->id}/supervisor/approve"))
            ->assertOk()
            ->assertJsonPath('data.enrollment_id', $enrollment->id)
            ->assertJsonStructure(['data' => ['user_id', 'npi']]);

        $enrollment->refresh();
        $this->assertSame('APPROVED', $enrollment->status);

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame('DOE', $user->name);
        $this->assertSame('JOHN', $user->first_name);
        $this->assertSame($phone, $user->phonenumber);
        $this->assertSame('FR', $user->nationality);
        $this->assertSame('ACTIVE', $user->status);
        $this->assertNotNull($user->npi);
        $this->assertStringStartsWith('F-', $user->npi);
        $this->assertTrue($user->hasRole('client'));

        $identity = Identity::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('APPROVED', $identity->status);
        $this->assertSame('IN_PERSON', $identity->type);
        $this->assertSame('ADVANCED', $identity->level);

        $this->assertNotNull(DB::table('password_resets')->where(['npi' => $user->npi, 'type' => 'all'])->first());
        $this->assertNotNull(DB::table('password_resets')->where(['npi' => $user->npi, 'type' => 'pin'])->first());
        $this->assertNotNull(DB::table('password_resets')->where(['npi' => $user->npi, 'type' => 'password'])->first());

        Bus::assertDispatched(WelcomeUserJob::class);
    }

    #[Test]
    public function agent_can_reject_pending_enrollment_with_standardized_reasons(): void
    {
        $email = 'foreigner.reject.agent@example.com';
        $phone = '+22990333444';
        $enrollmentId = $this->submitVerifiedEnrollment($email, $phone);

        Sanctum::actingAs($this->agent);

        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/claim"))
            ->assertOk();

        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/reject"), [
            'stage' => 'KYC',
            'reasons' => ['doc_invalid', 'photo_mismatch'],
            'comments' => 'Pièce non conforme',
        ])->assertOk();

        $enrollment = EnrollmentRequest::query()->findOrFail($enrollmentId);
        $this->assertSame('REJECTED', $enrollment->status);
        $this->assertSame('KYC', $enrollment->reject_stage);
        $this->assertSame(['doc_invalid', 'photo_mismatch'], $enrollment->reject_reasons);
        $this->assertSame('Pièce non conforme', $enrollment->review_comments);

        Bus::assertDispatched(SendEmailNotificationJob::class);
        $this->assertNull(User::query()->where('email', $email)->first());
    }

    #[Test]
    public function supervisor_can_reject_after_agent_approval(): void
    {
        $email = 'foreigner.reject.supervisor@example.com';
        $phone = '+22990555666';
        $enrollmentId = $this->submitVerifiedEnrollment($email, $phone);

        Sanctum::actingAs($this->agent);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/claim"))->assertOk();
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/approve"))->assertOk();

        Sanctum::actingAs($this->supervisor);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/supervisor/reject"), [
            'stage' => 'KYC',
            'reasons' => ['insufficient_evidence'],
            'comments' => 'Dossier incomplet',
        ])->assertOk();

        $enrollment = EnrollmentRequest::query()->findOrFail($enrollmentId);
        $this->assertSame('REJECTED', $enrollment->status);
        Bus::assertDispatched(SendEmailNotificationJob::class);
        $this->assertNull(User::query()->where('email', $email)->first());
    }

    #[Test]
    public function claim_conflicts_when_another_agent_already_owns_the_request(): void
    {
        $enrollmentId = $this->submitVerifiedEnrollment('foreigner.claim@example.com', '+22990777888');

        Sanctum::actingAs($this->agent);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/claim"))
            ->assertOk();

        $otherAgent = User::factory()->create(['status' => 'ACTIVE']);
        $otherAgent->assignRole('tech_two');
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

        $search = $this->getJson($this->api('/management/identity-reviews?status=PENDING&q=filter.pending@example.com'))
            ->assertOk()
            ->json('data.data');
        $this->assertCount(1, $search);
        $this->assertSame($pendingId, $search[0]['id']);
    }

    #[Test]
    public function policy_denies_client_access_to_identity_reviews(): void
    {
        $enrollmentId = $this->submitVerifiedEnrollment('policy.client@example.com', '+22993333000');

        $client = User::factory()->create(['status' => 'ACTIVE']);
        $client->assignRole('client');
        Sanctum::actingAs($client);

        $this->getJson($this->api('/management/identity-reviews'))->assertForbidden();
        $this->getJson($this->api("/management/identity-reviews/{$enrollmentId}"))->assertForbidden();
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
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/supervisor/reject"), [
            'stage' => 'KYC',
            'reasons' => ['insufficient_evidence'],
        ])->assertForbidden();
    }

    private function submitVerifiedEnrollment(string $email, string $phone): int
    {
        $this->postJson($this->api('/foreigner/send-otp'), ['email' => $email])
            ->assertOk();
        Bus::assertDispatched(ForeignerOtpJob::class);

        $emailOtp = Cache::get('foreigner_otp_'.strtolower($email));
        $this->assertNotNull($emailOtp);
        $this->postJson($this->api('/foreigner/verify-otp'), [
            'email' => $email,
            'otp' => $emailOtp,
        ])->assertOk();

        $this->postJson($this->api('/foreigner/send-otp'), ['phonenumber' => $phone])
            ->assertOk();
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
            'sex' => 'M',
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
