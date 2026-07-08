<?php

namespace Tests\Feature;

use App\Jobs\AdvancedIdRequestJob;
use App\Jobs\ForeignerFinalizedJob;
use App\Jobs\ForeignerInitRegistrationJob;
use App\Jobs\ForeignerOtpJob;
use App\Jobs\PlanifiedEmailJob;
use App\Models\PendingRegistration;
use App\Models\UserPackage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ForeignerEnrollmentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('filesystems.cloud', 'public');
        Storage::fake('public');
        Bus::fake();
        Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
    }

    protected function mockKkiapay(int $packageId, int $amount): void
    {
        Mockery::close();
        Mockery::mock('overload:Kkiapay\\Kkiapay')
            ->shouldReceive('verifyTransaction')
            ->andReturn((object) ['state' => [json_encode(['package' => $packageId, 'amount' => $amount])]]);
    }

    public function test_send_otp_success(): void
    {
        $email = 'user@example.com';

        $resp = $this->postJson($this->api('/foreigner/send-otp'), ['email' => $email]);
        $resp->assertStatus(200)
            ->assertJsonStructure(['success', 'message', 'data' => ['email']]);

        Bus::assertDispatched(ForeignerOtpJob::class);
        $this->assertNotNull(Cache::get('foreigner_otp_'.strtolower($email)));
    }

    public function test_send_otp_validation_error_returns_api_envelope(): void
    {
        $resp = $this->postJson($this->api('/foreigner/send-otp'), []);

        $resp->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Données invalides.',
                'status' => 422,
            ])
            ->assertJsonStructure(['data' => ['email']]);
    }

    public function test_verify_otp_success_and_sets_validation_flag(): void
    {
        $email = 'verify@example.com';
        Cache::put('foreigner_otp_'.strtolower($email), '123456', now()->addMinutes(5));

        $resp = $this->postJson($this->api('/foreigner/verify-otp'), ['email' => $email, 'otp' => '123456']);
        $resp->assertStatus(200)
            ->assertJsonStructure(['success', 'message', 'data' => ['email']]);

        $this->assertTrue((bool) Cache::get('foreigner_otp_valid_'.strtolower($email)));
    }

    public function test_verify_otp_invalid(): void
    {
        $email = 'bad@example.com';
        Cache::put('foreigner_otp_'.strtolower($email), '654321', now()->addMinutes(1));

        $resp = $this->postJson($this->api('/foreigner/verify-otp'), ['email' => $email, 'otp' => '999999']);
        $resp->assertStatus(400);
    }

    public function test_init_registration_requires_verified_otp(): void
    {
        $email = 'init@example.com';

        $resp = $this->postJson($this->api('/foreigner/register/init'), ['email' => $email]);
        $resp->assertStatus(400);

        Cache::put('foreigner_otp_valid_'.strtolower($email), true, now()->addMinutes(10));
        $resp2 = $this->postJson($this->api('/foreigner/register/init'), ['email' => $email]);
        $resp2->assertStatus(200)
            ->assertJsonStructure(['success', 'message', 'data' => ['registration_token', 'expires_at', 'link']]);

        Bus::assertDispatched(ForeignerInitRegistrationJob::class);
    }

    public function test_finalize_registration_online_creates_user_identity_subscription_and_structure(): void
    {
        $email = 'finalize@example.com';
        Cache::put('foreigner_otp_valid_'.strtolower($email), true, now()->addMinutes(10));
        $token = str_repeat('a', 64);
        PendingRegistration::create([
            'npi' => '',
            'registration_token' => $token,
            'email' => $email,
            'status' => 'PENDING',
            'expires_at' => Carbon::now()->addHour(),
            'profile_path' => null,
            'user_data' => [
                'is_foreigner' => true,
                'form' => [],
                'cached_data' => [],
            ],
        ]);

        // validateSubscription() checks structure_packages id=7
        DB::table('structure_packages')->insert([
            'id' => 7,
            'prix' => 5000,
            'validity' => 30,
            'quantity' => 1,
            'type' => 'VID',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // createSubscriptionRecord() FK requires user_packages id=7
        DB::table('user_packages')->insert([
            'id' => 7,
            'prix' => 5000,
            'validity' => 30,
            'quantity' => 1,
            'type' => 'VID',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $files = [
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
            'recto' => UploadedFile::fake()->image('recto.jpg'),
            'verso' => UploadedFile::fake()->image('verso.jpg'),
            'structure.attachements.0.files.0' => UploadedFile::fake()->create('doc1.pdf', 10, 'application/pdf'),
        ];

        $payload = [
            'registration_token' => $token,
            'transaction_id' => 'TX123',
            'type' => 'ONLINE',
            'level' => 'ADVANCED',
            'similarity' => '0.98',
            'liveness' => 'PASS',
            'kyc' => [
                'name' => 'DOE',
                'first_name' => 'JOHN',
                'phonenumber' => '12345678',
                'nationality' => 'FR',
                'document_type' => 'PASSPORT',
                'document_number' => 'P1234567',
            ],
            'structure' => [
                'name' => 'ACME',
                'ifu' => 'IFU-123',
                'attachements' => [
                    [
                        'name' => 'RCCM',
                        'status' => 'SENT',
                    ],
                ],
            ],
        ];

        $resp = $this->postJson($this->api('/foreigner/register/finalize'), array_merge($payload, $files));
        $resp->dump();
        $resp->assertStatus(200)
            ->assertJsonStructure(['success', 'message', 'data' => ['user_id', 'phonenumber']]);

        Bus::assertDispatched(AdvancedIdRequestJob::class);
        Bus::assertDispatched(ForeignerFinalizedJob::class);
    }

    public function test_finalize_registration_in_person_sends_planified_notification(): void
    {
        $email = 'inperson@example.com';
        Cache::put('foreigner_otp_valid_'.strtolower($email), true, now()->addMinutes(10));
        $token = str_repeat('b', 64);
        PendingRegistration::create([
            'npi' => '',
            'registration_token' => $token,
            'email' => $email,
            'status' => 'PENDING',
            'expires_at' => Carbon::now()->addHour(),
            'profile_path' => null,
            'user_data' => [
                'is_foreigner' => true,
                'form' => [],
                'cached_data' => [],
            ],
        ]);

        $package = UserPackage::create([
            'prix' => 1000,
            'validity' => 30,
            'quantity' => 1,
            'type' => 'VID',
        ]);
        $this->mockKkiapay($package->id, $package->prix);

        $payload = [
            'registration_token' => $token,
            'transaction_id' => 'TX999',
            'type' => 'IN_PERSON',
            'level' => 'ADVANCED',
            'date' => Carbon::now()->addDays(2)->toISOString(),
            'kyc' => [
                'name' => 'SMITH',
            ],
        ];

        $resp = $this->postJson($this->api('/foreigner/register/finalize'), $payload);
        $resp->assertStatus(200);

        Bus::assertDispatched(PlanifiedEmailJob::class);
        Bus::assertDispatched(ForeignerFinalizedJob::class);
    }
}
