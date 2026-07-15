<?php

namespace Tests\Feature;

use App\Jobs\ForeignerFinalizedJob;
use App\Jobs\ForeignerOtpJob;
use App\Jobs\RegulaAnalysisJob;
use App\Jobs\SendSmsJob;
use App\Jobs\UploadEnrollmentFilesJob;
use App\Models\EnrollmentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ForeignerEnrollmentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('filesystems.cloud', 'public');
        Storage::fake('public');
        Storage::fake('local');
        Bus::fake();
    }

    public function test_send_email_otp_success(): void
    {
        $email = 'user@example.com';

        $resp = $this->postJson($this->api('/foreigner/send-otp'), ['email' => $email]);
        $resp->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', $email)
            ->assertJsonPath('data.channel', 'email');

        Bus::assertDispatched(ForeignerOtpJob::class);
        $this->assertNotNull(Cache::get('foreigner_otp_'.strtolower($email)));
    }

    public function test_send_phone_otp_success(): void
    {
        $phone = '+22990123456';

        $resp = $this->postJson($this->api('/foreigner/send-otp'), ['phonenumber' => $phone]);
        $resp->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.channel', 'phone');

        Bus::assertDispatched(SendSmsJob::class);
        $this->assertNotNull(Cache::get('foreigner_otp_phone_'.$phone));
    }

    public function test_send_otp_validation_error_returns_api_envelope(): void
    {
        $resp = $this->postJson($this->api('/foreigner/send-otp'), []);

        $resp->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Données invalides.',
                'status' => 422,
            ]);
    }

    public function test_verify_email_otp_success_and_sets_validation_flag(): void
    {
        $email = 'verify@example.com';
        Cache::put('foreigner_otp_'.strtolower($email), '123456', now()->addMinutes(5));

        $resp = $this->postJson($this->api('/foreigner/verify-otp'), [
            'email' => $email,
            'otp' => '123456',
        ]);
        $resp->assertStatus(200)
            ->assertJsonPath('data.channel', 'email');

        $this->assertTrue((bool) Cache::get('foreigner_otp_valid_'.strtolower($email)));
    }

    public function test_verify_phone_otp_success_and_sets_validation_flag(): void
    {
        $phone = '+22990123456';
        Cache::put('foreigner_otp_phone_'.$phone, '654321', now()->addMinutes(5));

        $resp = $this->postJson($this->api('/foreigner/verify-otp'), [
            'phonenumber' => $phone,
            'otp' => '654321',
        ]);
        $resp->assertStatus(200)
            ->assertJsonPath('data.channel', 'phone');

        $this->assertTrue((bool) Cache::get('foreigner_otp_valid_phone_'.$phone));
    }

    public function test_verify_otp_invalid(): void
    {
        $email = 'bad@example.com';
        Cache::put('foreigner_otp_'.strtolower($email), '654321', now()->addMinutes(1));

        $resp = $this->postJson($this->api('/foreigner/verify-otp'), [
            'email' => $email,
            'otp' => '999999',
        ]);
        $resp->assertStatus(400);
    }

    public function test_submit_enrollment_requires_verified_email_otp(): void
    {
        $phone = '+22990123456';
        Cache::put('foreigner_otp_valid_phone_'.$phone, true, now()->addMinutes(10));

        $resp = $this->post($this->api('/foreigner/enroll'), $this->enrollmentPayload(
            email: 'init@example.com',
            phone: $phone,
        ));

        $resp->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_submit_enrollment_requires_verified_phone_otp(): void
    {
        $email = 'init@example.com';
        Cache::put('foreigner_otp_valid_'.strtolower($email), true, now()->addMinutes(10));

        $resp = $this->post($this->api('/foreigner/enroll'), $this->enrollmentPayload(
            email: $email,
            phone: '+22990123456',
        ));

        $resp->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_submit_enrollment_creates_pending_request_and_dispatches_jobs(): void
    {
        $email = 'finalize@example.com';
        $phone = '+22990123456';

        Cache::put('foreigner_otp_valid_'.strtolower($email), true, now()->addMinutes(10));
        Cache::put('foreigner_otp_valid_phone_'.$phone, true, now()->addMinutes(10));

        $resp = $this->post($this->api('/foreigner/enroll'), $this->enrollmentPayload(
            email: $email,
            phone: $phone,
        ));

        $resp->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data' => ['enrollment_request_id']]);

        $enrollmentId = $resp->json('data.enrollment_request_id');
        $this->assertDatabaseHas('enrollment_requests', [
            'id' => $enrollmentId,
            'email' => $email,
            'phonenumber' => $phone,
            'status' => 'PENDING',
            'type' => 'PERSONNE_PHYSIQUE',
        ]);

        $enrollment = EnrollmentRequest::find($enrollmentId);
        $this->assertSame('DOE', $enrollment->kyc_data['name']);
        $this->assertSame('PASSPORT', $enrollment->kyc_data['document_type']);

        Bus::assertChained([
            UploadEnrollmentFilesJob::class,
            RegulaAnalysisJob::class,
            ForeignerFinalizedJob::class,
        ]);

        $this->assertNull(Cache::get('foreigner_otp_valid_'.strtolower($email)));
        $this->assertNull(Cache::get('foreigner_otp_valid_phone_'.$phone));
    }

    /**
     * @return array<string, mixed>
     */
    private function enrollmentPayload(string $email, string $phone): array
    {
        return [
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
        ];
    }
}
