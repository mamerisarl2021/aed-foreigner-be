<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\AgentAvis;
use App\Enums\EnrollmentStatus;
use App\Models\EnrollmentRequest;
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

final class PersonnePhysiqueEnrollmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

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
        Bus::fake();

        Role::firstOrCreate(['name' => config('roles.agent'), 'guard_name' => 'web']);

        $this->agent = User::factory()->create(['email' => 'agent-physique@example.com']);
        $this->agent->assignRole(config('roles.agent'));
    }

    #[Test]
    public function submit_is_rejected_without_verified_otp(): void
    {
        $this->post($this->api('/enrolements/etrangers'), $this->physiquePayload('physique-no-otp@example.com'))
            ->assertStatus(400)
            ->assertJsonPath('message', "Veuillez d'abord vérifier l'OTP email et téléphone.");
    }

    #[Test]
    public function otp_verify_rejects_an_invalid_code(): void
    {
        $this->postJson($this->api('/otp/send'), [
            'email' => 'physique-bad-otp@example.com',
            'phonenumber' => '+2290162405472',
        ])->assertOk();

        $this->postJson($this->api('/otp/verify'), [
            'email' => 'physique-bad-otp@example.com',
            'phonenumber' => '+2290162405472',
            'otp' => '000000',
        ])
            ->assertStatus(400)
            ->assertJsonPath('message', 'OTP invalide ou expiré.');
    }

    #[Test]
    public function otp_and_kyc_then_submit_creates_a_demande_without_a_user(): void
    {
        $email = 'physique-ok@example.com';
        $this->markOtpVerified($email, '+2290162405472');

        $this->post($this->api('/kyc/verify'), [
            'email' => $email,
            'phonenumber' => '+2290162405472',
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
            'recto' => UploadedFile::fake()->image('recto.jpg'),
        ])->assertOk();

        $usersBefore = User::query()->count();

        $this->post($this->api('/enrolements/etrangers'), $this->physiquePayload($email))
            ->assertStatus(202)
            ->assertJsonPath('data.statut', EnrollmentStatus::EnAttenteAgent->value);

        $enrollment = EnrollmentRequest::query()->where('email', $email)->first();
        $this->assertNotNull($enrollment);
        $this->assertSame('PERSONNE_PHYSIQUE', $enrollment->type);
        $this->assertNotNull($enrollment->tracking_code);
        $this->assertSame($usersBefore, User::query()->count());
        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    #[Test]
    public function an_agent_can_claim_and_instruct_a_physique_demande(): void
    {
        $email = 'physique-instruction@example.com';
        $this->markOtpVerified($email, '+2290162405472');

        $this->post($this->api('/kyc/verify'), [
            'email' => $email,
            'phonenumber' => '+2290162405472',
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
            'recto' => UploadedFile::fake()->image('recto.jpg'),
        ])->assertOk();

        $this->post($this->api('/enrolements/etrangers'), $this->physiquePayload($email))
            ->assertStatus(202);

        $enrollment = EnrollmentRequest::query()->where('email', $email)->firstOrFail();

        Sanctum::actingAs($this->agent);

        $this->patchJson($this->api("/enrolements/{$enrollment->id}/prise-en-charge"))
            ->assertOk()
            ->assertJsonPath('data.statut', EnrollmentStatus::EnCoursAgent->value);

        $this->patchJson($this->api("/enrolements/{$enrollment->id}/instruction"), [
            'avis' => AgentAvis::Favorable->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.statut', EnrollmentStatus::EnAttenteResponsable->value)
            ->assertJsonPath('data.avis_agent', AgentAvis::Favorable->value);
    }

    /**
     * @return array<string, mixed>
     */
    private function physiquePayload(string $email): array
    {
        return [
            'email' => $email,
            'phonenumber' => '+2290162405472',
            'name' => 'KOTO',
            'first_name' => 'Ada',
            'sexe' => 'F',
            'date_of_birth' => '1990-05-12',
            'place_of_birth' => 'Cotonou',
            'nationality' => 'BJ',
            'country_of_residence' => 'BJ',
            'address' => 'Cotonou',
            'document_type' => 'PASSPORT',
            'document_number' => 'BJ1234567',
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
            'recto' => UploadedFile::fake()->image('recto.jpg'),
        ];
    }

    private function markOtpVerified(string $email, string $phone): void
    {
        Cache::put('enrollment_otp_verified_email_'.$email, true, 600);
        Cache::put('enrollment_otp_verified_phone_'.$phone, true, 600);
    }
}
