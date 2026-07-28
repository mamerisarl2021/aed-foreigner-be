<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $name = config('seeding.admin.name');
        $email = config('seeding.admin.email');
        $password = config('seeding.admin.password');

        if (! $email || ! $password) {
            $this->command?->warn('Skipping admin seed: SEED_ADMIN_EMAIL and SEED_ADMIN_PASSWORD must be set.');

            return;
        }

        if (app()->isProduction() && $password === 'Secret123!') {
            throw new \RuntimeException('Refusing to seed the default admin password in production. Set a strong SEED_ADMIN_PASSWORD.');
        }

        Role::firstOrCreate([
            'name' => config('roles.administrateur_plateforme'),
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

        if (! $admin->hasRole(config('roles.administrateur_plateforme'))) {
            $admin->assignRole(config('roles.administrateur_plateforme'));
        }

        $this->command?->info("Administrator seeded: {$email}");
    }
}
