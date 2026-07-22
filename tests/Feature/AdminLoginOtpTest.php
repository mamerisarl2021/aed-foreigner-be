<?php

namespace Tests\Feature;

use App\Jobs\SendOTPJob;
use App\Models\OTP;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminLoginOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();

        Role::firstOrCreate([
            'name' => config('roles.administrateur_plateforme'),
            'guard_name' => 'web',
        ]);
    }

    public function test_admin_login_stores_otp_for_staff_email(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'Secret123!',
            'status' => 'ACTIVE',
        ]);
        $admin->assignRole(config('roles.administrateur_plateforme'));

        $this->postJson($this->api('/admin/login'), [
            'email' => 'admin@example.com',
            'password' => 'Secret123!',
        ])->assertOk();

        $otp = OTP::query()->where('email', 'admin@example.com')->first();

        $this->assertNotNull($otp);
        $this->assertSame(6, strlen($otp->otp));
        $this->assertTrue(now()->lt($otp->valid_until));

        Bus::assertDispatched(SendOTPJob::class, function (SendOTPJob $job) use ($otp) {
            return $job->user === 'admin@example.com' && $job->code === $otp->otp;
        });
    }

    public function test_admin_verify_otp_returns_access_token(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'Secret123!',
            'status' => 'ACTIVE',
        ]);
        $admin->assignRole(config('roles.administrateur_plateforme'));

        $this->postJson($this->api('/admin/login'), [
            'email' => 'admin@example.com',
            'password' => 'Secret123!',
        ])->assertOk();

        $otp = OTP::query()->where('email', 'admin@example.com')->value('otp');

        $this->postJson($this->api('/admins/verify-otp'), [
            'email' => 'admin@example.com',
            'otp' => $otp,
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token', 'user', 'roles']]);

        $this->assertNull(OTP::query()->where('email', 'admin@example.com')->first());
    }
}
