<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ManageAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_admin_persists_hashed_password(): void
    {
        $this->artisan('manage:admin', [
            'action' => 'create',
            '--name' => 'Admin',
            '--email' => 'admin@example.com',
            '--password' => 'Secret123!',
            '--password-confirmation' => 'Secret123!',
        ])->assertSuccessful();

        $admin = User::where('email', 'admin@example.com')->first();

        $this->assertNotNull($admin);
        $this->assertTrue(Hash::check('Secret123!', $admin->password));
        $this->assertTrue($admin->hasRole(config('roles.administrateur_plateforme')));
    }

    public function test_update_admin_persists_new_password(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'OldSecret123!',
        ]);
        $admin->assignRole(config('roles.administrateur_plateforme'));

        $this->artisan('manage:admin', [
            'action' => 'update',
            '--name' => 'Updated Admin',
            '--email' => 'admin@example.com',
            '--password' => 'NewSecret123!',
            '--password-confirmation' => 'NewSecret123!',
        ])->assertSuccessful();

        $admin->refresh();

        $this->assertSame('Updated Admin', $admin->name);
        $this->assertTrue(Hash::check('NewSecret123!', $admin->password));
    }
}
