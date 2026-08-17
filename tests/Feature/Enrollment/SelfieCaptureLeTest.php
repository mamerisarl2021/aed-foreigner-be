<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Jobs\RegulaAnalysisJob;
use App\Models\EnrollmentRequest;
use App\Models\Identity;
use App\Models\User;
use App\Services\Regula\RegulaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SelfieCaptureLeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filesystems.cloud' => 's3',
            'services.regula.mock' => true,
        ]);
        Storage::fake('s3');
        Storage::fake('local');

        foreach ([
            config('roles.agent'),
            config('roles.client'),
        ] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function physique_kyc_persists_the_client_capture_instant_on_the_demande(): void
    {
        Bus::fake();
        $this->markOtpVerified('physique-capture@example.com', '+2290162405472');

        $capturedAt = '2026-03-06T14:30:00+01:00';

        $this->post($this->api('/kyc/verify'), [
            'email' => 'physique-capture@example.com',
            'phonenumber' => '+2290162405472',
            'capture_le' => $capturedAt,
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
            'recto' => UploadedFile::fake()->image('recto.jpg'),
        ])->assertOk();

        $this->post($this->api('/enrolements/etrangers'), [
            'email' => 'physique-capture@example.com',
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
        ])->assertStatus(202);

        $enrollment = EnrollmentRequest::query()->where('email', 'physique-capture@example.com')->first();
        $this->assertNotNull($enrollment);
        $this->assertTrue(
            Carbon::parse((string) ($enrollment->analysis_details['selfie_captured_at'] ?? null))
                ->equalTo(Carbon::parse($capturedAt))
        );

        $agent = User::factory()->create(['email' => 'agent-capture@example.com']);
        $agent->assignRole(config('roles.agent'));
        Sanctum::actingAs($agent);

        $this->getJson($this->api("/enrolements/{$enrollment->id}"))
            ->assertOk()
            ->assertJsonPath('data.analyse_kyc.selfie.capture_le', $enrollment->analysis_details['selfie_captured_at']);
    }

    #[Test]
    public function physique_kyc_defaults_capture_le_to_the_verification_time(): void
    {
        Bus::fake();
        $this->freezeTime();
        $this->markOtpVerified('physique-default@example.com', '+2290162405472');

        $this->post($this->api('/kyc/verify'), [
            'email' => 'physique-default@example.com',
            'phonenumber' => '+2290162405472',
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
            'recto' => UploadedFile::fake()->image('recto.jpg'),
        ])->assertOk();

        $this->post($this->api('/enrolements/etrangers'), [
            'email' => 'physique-default@example.com',
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
        ])->assertStatus(202);

        $enrollment = EnrollmentRequest::query()->where('email', 'physique-default@example.com')->first();
        $this->assertNotNull($enrollment);
        $this->assertSame(now()->toIso8601String(), $enrollment->analysis_details['selfie_captured_at'] ?? null);
    }

    #[Test]
    public function morale_kyc_persists_capture_le_on_the_demande(): void
    {
        Bus::fake();
        Role::firstOrCreate(['name' => config('roles.demandeur_authentifie'), 'guard_name' => 'web']);

        $client = User::factory()->create([
            'email' => 'morale-capture@example.com',
            'phonenumber' => '+2290162405472',
            'status' => 'ACTIVE',
        ]);
        $client->assignRole(config('roles.client'));
        Identity::query()->create([
            'user_id' => $client->id,
            'type' => 'IN_PERSON',
            'level' => 'ADVANCED',
            'status' => 'APPROVED',
            'proof' => json_encode(['selfiePath' => '']),
        ]);

        Sanctum::actingAs($client);

        $capturedAt = '2026-04-01T09:15:00+01:00';
        $this->post($this->api('/kyc/verify'), [
            'email' => $client->email,
            'phonenumber' => $client->phonenumber,
            'capture_le' => $capturedAt,
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
            'recto' => UploadedFile::fake()->image('recto.jpg'),
        ])->assertOk();

        $this->post($this->api('/enrolements/morales'), [
            'email' => 'entreprise-capture@example.com',
            'phonenumber' => '+2290162405472',
            'legal_name' => 'TECH SARL CAPTURE',
            'country_of_incorporation' => 'Canada',
            'registration_number' => 'RCCM-CA-CAPTURE',
            'headquarters_address' => 'Cotonou',
            'activity_sector' => 'Services',
            'legal_representative_name' => 'KOTO',
            'legal_representative_first_name' => 'Ada',
            'is_legal_representative' => '1',
            'trade_register_extract' => UploadedFile::fake()->create('rccm.pdf', 100, 'application/pdf'),
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
            'recto' => UploadedFile::fake()->image('recto.jpg'),
            'capture_le' => $capturedAt,
        ])->assertOk();

        $enrollment = EnrollmentRequest::query()->where('type', 'PERSONNE_MORALE')->first();
        $this->assertNotNull($enrollment);
        $this->assertTrue(
            Carbon::parse((string) ($enrollment->analysis_details['selfie_captured_at'] ?? null))
                ->equalTo(Carbon::parse($capturedAt))
        );
    }

    #[Test]
    public function regula_reanalysis_keeps_the_stored_selfie_capture_instant(): void
    {
        $capturedAt = '2026-03-06T14:30:00+01:00';
        Storage::disk('s3')->put('selfies/face.jpg', 'selfie');
        Storage::disk('s3')->put('images/recto.jpg', 'recto');

        $enrollment = EnrollmentRequest::query()->create([
            'email' => 'reanalysis-capture@example.com',
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::EnAttenteAgent->value,
            'type' => 'PERSONNE_PHYSIQUE',
            'kyc_data' => ['name' => 'KOTO'],
            'documents' => [
                'selfie' => 'selfies/face.jpg',
                'recto' => 'images/recto.jpg',
            ],
            'analysis_details' => [
                'selfie_captured_at' => $capturedAt,
                'legacy' => true,
            ],
        ]);

        (new RegulaAnalysisJob($enrollment->id))->handle($this->app->make(RegulaService::class));

        $enrollment->refresh();
        $this->assertSame($capturedAt, $enrollment->analysis_details['selfie_captured_at'] ?? null);
        $this->assertArrayNotHasKey('legacy', $enrollment->analysis_details ?? []);
        $this->assertTrue($enrollment->analysis_details['mock'] ?? false);
    }

    private function markOtpVerified(string $email, string $phone): void
    {
        Cache::put('enrollment_otp_verified_email_'.$email, true, 600);
        Cache::put('enrollment_otp_verified_phone_'.$phone, true, 600);
    }
}
