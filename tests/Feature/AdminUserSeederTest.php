<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_administrator_with_configured_credentials(): void
    {
        $this->seed(\Database\Seeders\AdminUserSeeder::class);

        $admin = User::where('email', config('seeding.admin.email'))->first();

        $this->assertNotNull($admin);
        $this->assertSame(config('seeding.admin.name'), $admin->name);
        $this->assertSame('ACTIVE', $admin->status);
        $this->assertTrue(Hash::check(config('seeding.admin.password'), $admin->password));
        $this->assertTrue($admin->hasRole(config('roles.administrateur_plateforme')));
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(\Database\Seeders\AdminUserSeeder::class);
        $this->seed(\Database\Seeders\AdminUserSeeder::class);

        $this->assertSame(1, User::where('email', config('seeding.admin.email'))->count());
    }
}
