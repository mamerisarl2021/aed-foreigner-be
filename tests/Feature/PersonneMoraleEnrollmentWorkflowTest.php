<?php

namespace Tests\Feature;

use App\Jobs\ForeignerFinalizedJob;
use App\Jobs\MoraleEmailVerificationJob;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Jobs\SendSmsJob;
use App\Jobs\UploadEnrollmentFilesJob;
use App\Models\EnrollmentRequest;
use App\Models\Identity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PersonneMoraleEnrollmentWorkflowTest extends TestCase
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
    }

    #[Test]
    public function happy_path_morale_submit_verify_and_supervisor_approve_creates_company_identity(): void
    {
        $client = $this->createFinalizedClient('rep@example.com');

        Sanctum::actingAs($client);
        $response = $this->post($this->api('/morale/enroll'), $this->moralePayload('company@example.com', '+22990101010'));
        $response->assertOk()->assertJsonPath('data.status', 'AWAITING_CONTACT_VERIFICATION');

        $enrollmentId = (int) $response->json('data.enrollment_request_id');
        $enrollment = EnrollmentRequest::findOrFail($enrollmentId);
        $this->assertSame('PERSONNE_MORALE', $enrollment->type);
        $this->assertSame($client->id, $enrollment->submitted_by_user_id);

        Bus::assertDispatched(MoraleEmailVerificationJob::class);

        $plainToken = str_repeat('b', 64);
        $enrollment->update(['email_verification_token' => hash('sha256', $plainToken)]);
        $this->postJson($this->api("/morale/enrollments/{$enrollmentId}/verify-email"), [
            'token' => $plainToken,
        ])->assertOk();

        $this->assertNotNull($enrollment->fresh()->email_verified_at);

        Sanctum::actingAs($client);
        $this->postJson($this->api("/morale/enrollments/{$enrollmentId}/send-phone-otp"))->assertOk();
        Bus::assertDispatched(SendSmsJob::class);

        $phoneOtp = Cache::get("morale_otp_phone_{$enrollmentId}");
        $this->assertNotNull($phoneOtp);
        $this->postJson($this->api("/morale/enrollments/{$enrollmentId}/verify-phone-otp"), [
            'otp' => $phoneOtp,
        ])->assertOk();

        $enrollment->refresh();
        $this->assertSame('PENDING', $enrollment->status);
        $this->assertNotNull($enrollment->sla_deadline_at);

        Sanctum::actingAs($this->agent);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/claim"))->assertOk();
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/approve"))->assertOk();

        Sanctum::actingAs($this->supervisor);
        $this->postJson($this->api("/management/identity-reviews/{$enrollmentId}/supervisor/approve"))
            ->assertOk()
            ->assertJsonPath('data.representative_user_id', $client->id);

        $enrollment->refresh();
        $this->assertSame('APPROVED', $enrollment->status);

        $this->assertSame(1, User::query()->where('email', 'rep@example.com')->count());

        $identity = Identity::query()
            ->where('user_id', $client->id)
            ->where('type', 'PERSONNE_MORALE')
            ->firstOrFail();
        $this->assertSame('APPROVED', $identity->status);
    }

    #[Test]
    public function guest_cannot_submit_morale_enrollment(): void
    {
        $this->post($this->api('/morale/enroll'), $this->moralePayload('guest@example.com', '+22990101011'))
            ->assertUnauthorized();
    }

    #[Test]
    public function client_without_finalized_physique_cannot_submit_morale(): void
    {
        $client = User::factory()->create(['status' => 'ACTIVE', 'email' => 'nofinal@example.com']);
        $client->assignRole('client');

        Sanctum::actingAs($client);
        $this->post($this->api('/morale/enroll'), $this->moralePayload('company2@example.com', '+22990101012'))
            ->assertStatus(403);
    }

    #[Test]
    public function agent_list_excludes_awaiting_contact_verification_by_default(): void
    {
        $client = $this->createFinalizedClient('awaiting@example.com');

        Sanctum::actingAs($client);
        $response = $this->post($this->api('/morale/enroll'), $this->moralePayload('awaiting-co@example.com', '+22990101013'));
        $enrollmentId = (int) $response->json('data.enrollment_request_id');

        Sanctum::actingAs($this->agent);
        $list = $this->getJson($this->api('/management/identity-reviews'));
        $list->assertOk();
        $ids = collect($list->json('data.data'))->pluck('id');
        $this->assertFalse($ids->contains($enrollmentId));
    }

    #[Test]
    public function client_cannot_view_other_users_morale_enrollment(): void
    {
        $owner = $this->createFinalizedClient('owner@example.com');
        $other = $this->createFinalizedClient('other@example.com');

        Sanctum::actingAs($owner);
        $enrollmentId = (int) $this->post($this->api('/morale/enroll'), $this->moralePayload('owned-co@example.com', '+22990101014'))
            ->json('data.enrollment_request_id');

        Sanctum::actingAs($other);
        $this->getJson($this->api("/morale/enrollments/{$enrollmentId}"))->assertForbidden();
    }

    #[Test]
    public function duplicate_enrolled_company_registration_is_rejected(): void
    {
        $client = $this->createFinalizedClient('dup@example.com');

        EnrollmentRequest::create([
            'email' => 'existing-co@example.com',
            'phonenumber' => '+22990000000',
            'kyc_data' => [
                'legal_name' => 'Existing Co',
                'country_of_incorporation' => 'FR',
                'registration_number' => 'RC-FR-001',
            ],
            'status' => 'APPROVED',
            'type' => 'PERSONNE_MORALE',
        ]);

        Sanctum::actingAs($client);
        $payload = $this->moralePayload('new-co@example.com', '+22990101015');
        $payload['registration_number'] = 'RC-FR-001';
        $payload['country_of_incorporation'] = 'FR';

        $this->post($this->api('/morale/enroll'), $payload)->assertStatus(409);
    }

    private function createFinalizedClient(string $email): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'email' => $email]);
        $user->assignRole('client');

        EnrollmentRequest::create([
            'email' => $email,
            'phonenumber' => '+22990000001',
            'kyc_data' => ['name' => 'REP', 'first_name' => 'Legal'],
            'status' => 'FINALIZED',
            'type' => 'PERSONNE_PHYSIQUE',
        ]);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function moralePayload(string $email, string $phone): array
    {
        return [
            'email' => $email,
            'phonenumber' => $phone,
            'legal_name' => 'ACME Foreign SARL',
            'legal_form' => 'SARL',
            'country_of_incorporation' => 'FR',
            'registration_number' => 'RC-FR-'.uniqid(),
            'incorporation_date' => '2010-05-01',
            'headquarters_address' => '1 rue Example, Paris',
            'activity_sector' => 'Services IT',
            'legal_representative_name' => 'DOE',
            'legal_representative_first_name' => 'JANE',
            'is_legal_representative' => true,
            'trade_register_extract' => UploadedFile::fake()->create('rc.pdf', 100, 'application/pdf'),
            'statutes' => UploadedFile::fake()->create('statuts.pdf', 100, 'application/pdf'),
        ];
    }
}
