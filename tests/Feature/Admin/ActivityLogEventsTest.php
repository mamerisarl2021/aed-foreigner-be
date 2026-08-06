<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\ActivityLogAction;
use App\Models\ActivityLog;
use App\Models\EnrollmentRequest;
use App\Models\OTP;
use App\Models\User;
use App\Services\ANIP\AnipSimulatorService;
use App\Services\Auth\AdminAuthService;
use App\Services\Enrollment\KycVerificationService;
use App\Services\Enrollment\PersonneMoraleEnrollmentService;
use App\Services\PasswordReset\ClientPasswordResetService;
use App\Services\PKI\TrustedXClientService;
use App\Services\Registration\UserRegistrationService;
use App\Services\Regula\MockRegulaService;
use App\Services\Regula\RegulaService;
use App\Traits\EncryptionTrait;
use Carbon\Carbon;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * §11 — dedicated coverage for journalized métier events.
 */
final class ActivityLogEventsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            config('roles.administrateur_plateforme'),
            config('roles.agent'),
            config('roles.client'),
        ] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create([
            'email' => 'admin-events@example.com',
            'password' => 'password',
            'status' => 'ACTIVE',
        ]);
        $this->admin->assignRole(config('roles.administrateur_plateforme'));

        config(['keycloak.staff.enabled' => false, 'services.regula.mock' => true]);
    }

    #[Test]
    public function admin_login_records_connexion_admin(): void
    {
        app(AdminAuthService::class)->loginDirect($this->admin);

        $this->assertTrue(
            ActivityLog::query()
                ->where('action_code', ActivityLogAction::ConnexionAdmin->label())
                ->where('actor_user_id', $this->admin->id)
                ->exists()
        );
    }

    #[Test]
    public function client_otp_send_and_verify_are_journalized(): void
    {
        $this->mock(AnipSimulatorService::class, function ($mock) {
            $mock->shouldReceive('getUserData')
                ->once()
                ->with('1234567890123')
                ->andReturn([
                    'status' => true,
                    'data' => [
                        'email' => 'client-otp@example.com',
                        'npi' => '1234567890123',
                        'role' => 'CLIENT',
                    ],
                ]);
        });

        $registration = app(UserRegistrationService::class);
        $registration->sendOtp('1234567890123');

        $this->assertTrue(
            ActivityLog::query()
                ->where('action_code', ActivityLogAction::OtpEnvoye->label())
                ->where('description', 'like', '%client%')
                ->exists()
        );

        $plain = '654321';
        OTP::query()->where('npi', '1234567890123')->update([
            'otp' => hash('sha256', $plain),
            'valid_until' => Carbon::now()->addMinutes(5),
        ]);
        Cache::put('user_1234567890123', [
            'data' => ['email' => 'client-otp@example.com', 'npi' => '1234567890123'],
        ], 600);

        $registration->verifyOtp('1234567890123', $plain);

        $this->assertTrue(
            ActivityLog::query()
                ->where('action_code', ActivityLogAction::OtpVerifie->label())
                ->where('description', 'like', '%client%')
                ->exists()
        );
    }

    #[Test]
    public function morale_email_and_phone_otp_are_journalized(): void
    {
        $owner = User::factory()->create(['email' => 'morale-owner@example.com']);
        $owner->assignRole(config('roles.client'));

        $token = Str::random(40);
        $enrollment = EnrollmentRequest::query()->create([
            'email' => 'entreprise@example.com',
            'phonenumber' => '+2290162405472',
            'status' => 'AWAITING_CONTACT_VERIFICATION',
            'type' => 'PERSONNE_MORALE',
            'submitted_by_user_id' => $owner->id,
            'email_verification_token' => hash('sha256', $token),
            'kyc_data' => ['raison_sociale' => 'ACME SA'],
        ]);

        $service = app(PersonneMoraleEnrollmentService::class);
        $service->verifyEmail($enrollment->id, $token);

        $this->assertTrue(
            ActivityLog::query()
                ->where('action_code', ActivityLogAction::OtpVerifie->label())
                ->where('enrollment_request_id', $enrollment->id)
                ->where('description', 'like', '%Email officiel%')
                ->exists()
        );

        $service->sendPhoneOtp($owner, $enrollment->id);
        $this->assertTrue(
            ActivityLog::query()
                ->where('action_code', ActivityLogAction::OtpEnvoye->label())
                ->where('enrollment_request_id', $enrollment->id)
                ->where('description', 'like', '%morale%')
                ->exists()
        );

        $otp = '111222';
        Cache::put("morale_otp_phone_{$enrollment->id}", hash('sha256', $otp), 300);
        Cache::forget("morale_otp_phone_attempts_{$enrollment->id}");

        $service->verifyPhoneOtp($owner, $enrollment->id, $otp);
        $this->assertTrue(
            ActivityLog::query()
                ->where('action_code', ActivityLogAction::OtpVerifie->label())
                ->where('enrollment_request_id', $enrollment->id)
                ->where('description', 'like', '%Téléphone officiel%')
                ->exists()
        );
    }

    #[Test]
    public function client_password_reset_link_is_journalized(): void
    {
        $client = User::factory()->create([
            'email' => 'reset-client@example.com',
            'npi' => '9876543210987',
        ]);

        $this->mock(TrustedXClientService::class, function ($mock) {
            $mock->shouldReceive('getUserWithNPI')
                ->once()
                ->andReturn(['status' => true, 'data' => ['id' => 'tx-1']]);
        });

        app(ClientPasswordResetService::class)->sendResetLink('9876543210987', 'password');

        $this->assertTrue(
            ActivityLog::query()
                ->where('action_code', ActivityLogAction::MotDePasseReinitialise->label())
                ->where('actor_user_id', $client->id)
                ->where('description', 'like', '%Lien de réinitialisation%')
                ->exists()
        );
        $this->assertDatabaseHas('password_resets', ['npi' => '9876543210987']);
    }

    #[Test]
    public function profile_update_is_journalized(): void
    {
        $user = User::factory()->create(['email' => 'profile@example.com', 'name' => 'Old']);
        Sanctum::actingAs($user);

        $result = app(UserRegistrationService::class)->updateUser($user->id, ['name' => 'Nouveau'], null);

        $this->assertTrue($result->success);
        $this->assertTrue(
            ActivityLog::query()
                ->where('action_code', ActivityLogAction::UtilisateurModifie->label())
                ->where('actor_user_id', $user->id)
                ->where('description', 'like', '%profil%')
                ->exists()
        );
    }

    #[Test]
    public function kyc_verify_is_journalized(): void
    {
        config(['services.regula.mock' => true]);
        $this->app->bind(RegulaService::class, MockRegulaService::class);

        $email = 'kyc@example.com';
        $phone = '+2290162405472';
        Cache::put('enrollment_otp_verified_email_'.$email, true, 600);
        Cache::put('enrollment_otp_verified_phone_'.ltrim($phone, '+'), true, 600);
        // PhoneNumber::normalize may keep +; mirror both common forms.
        Cache::put('enrollment_otp_verified_phone_'.$phone, true, 600);

        $request = Request::create('/kyc/verify', 'POST', [
            'email' => $email,
            'phonenumber' => $phone,
        ], [], [
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
            'recto' => UploadedFile::fake()->image('recto.jpg'),
        ]);

        $result = app(KycVerificationService::class)->verify($request);

        $this->assertTrue($result->success, $result->message);
        $this->assertTrue(
            ActivityLog::query()
                ->where('action_code', ActivityLogAction::KycVerifie->label())
                ->where('description', 'like', '%réussie%')
                ->exists()
        );
    }

    #[Test]
    public function decrypt_document_is_journalized(): void
    {
        config(['filesystems.default' => 'local']);
        file_put_contents(storage_path('aed-public.key'), str_repeat('a', 32));

        $docsDir = storage_path('app/private/public/docs');
        if (! is_dir($docsDir)) {
            mkdir($docsDir, 0777, true);
        }

        $helper = new class
        {
            use EncryptionTrait;
        };
        $tmp = tempnam(sys_get_temp_dir(), 'enc');
        file_put_contents($tmp, 'plain-content');
        $filename = 'journal-decrypt-'.Str::random(8).'.bin';
        $helper->storeEncFile($filename, $tmp, 'docs');
        @unlink($tmp);

        Sanctum::actingAs($this->admin);

        $this->get($this->api('/decrypt/token/file/'.$filename))->assertOk();

        $this->assertTrue(
            ActivityLog::query()
                ->where('action_code', ActivityLogAction::DocumentDechiffre->label())
                ->where('actor_user_id', $this->admin->id)
                ->exists()
        );
    }

    #[Test]
    public function staff_seeder_journals_utilisateur_cree_on_first_create(): void
    {
        config([
            'seeding.admin' => [
                'name' => 'Seed Admin',
                'email' => 'seed-admin@example.com',
                'password' => 'Secret123!',
            ],
        ]);

        $this->seed(AdminUserSeeder::class);

        $this->assertTrue(
            ActivityLog::query()
                ->where('action_code', ActivityLogAction::UtilisateurCree->label())
                ->where('description', 'like', '%seeder%')
                ->exists()
        );

        ActivityLog::query()->delete();
        $this->seed(AdminUserSeeder::class);

        $this->assertFalse(
            ActivityLog::query()
                ->where('action_code', ActivityLogAction::UtilisateurCree->label())
                ->exists()
        );
    }

    #[Test]
    public function client_login_journals_connexion_client_with_actor(): void
    {
        $client = User::factory()->create([
            'email' => 'login-client@example.com',
            'npi' => '1111222233334',
            'status' => 'ACTIVE',
        ]);

        $this->mock(TrustedXClientService::class, function ($mock) use ($client) {
            $mock->shouldReceive('userInfo')
                ->once()
                ->andReturn([
                    'status' => true,
                    'data' => [
                        'user' => $client,
                        'token' => 'sanctum-token',
                        'pki_token' => 'pki-token',
                    ],
                ]);
        });

        $result = app(UserRegistrationService::class)->login('auth-code');

        $this->assertTrue($result->success);
        $this->assertTrue(
            ActivityLog::query()
                ->where('action_code', ActivityLogAction::ConnexionClient->label())
                ->where('actor_user_id', $client->id)
                ->exists()
        );
    }

    #[Test]
    public function admin_set_password_is_journalized(): void
    {
        $this->mock(TrustedXClientService::class, function ($mock) {
            $mock->shouldReceive('getUserWithNPI')
                ->once()
                ->andReturn(['status' => true, 'data' => ['id' => 'tx-user-1']]);
            $mock->shouldReceive('setDefaultPassword')
                ->once()
                ->andReturn(['status' => true, 'data' => []]);
        });

        $result = app(UserRegistrationService::class)->setPassword(
            '5555666677778',
            'NewSecret1!',
            'password',
            $this->admin,
        );

        $this->assertTrue($result->success);
        $this->assertTrue(
            ActivityLog::query()
                ->where('action_code', ActivityLogAction::MotDePasseChange->label())
                ->where('actor_user_id', $this->admin->id)
                ->where('description', 'like', '%NPI%')
                ->exists()
        );
    }
}
