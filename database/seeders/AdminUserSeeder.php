<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $name = config('seeding.admin.name');
        $email = config('seeding.admin.email');
        $password = config('seeding.admin.password');
        $role = config('roles.administrateur_plateforme');

        if (! $email || ! $password) {
            $this->command?->warn('Skipping admin seed: SEED_ADMIN_EMAIL and SEED_ADMIN_PASSWORD must be set.');

            return;
        }

        if (app()->isProduction() && $password === 'Secret123!') {
            throw new \RuntimeException('Refusing to seed the default admin password in production. Set a strong SEED_ADMIN_PASSWORD.');
        }

        Role::firstOrCreate([
            'name' => $role,
            'guard_name' => 'web',
        ]);

        $admin = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'status' => 'ACTIVE',
            ],
        );

        $admin->forceFill([
            'name' => $name,
            'password' => $password,
            'status' => 'ACTIVE',
        ])->save();

        $admin->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info("Administrator seeded: {$email} (role: {$role})");
    }
}
