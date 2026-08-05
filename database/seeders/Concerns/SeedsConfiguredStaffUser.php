<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use App\Enums\ActivityLogAction;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

trait SeedsConfiguredStaffUser
{
    protected function seedConfiguredStaffUser(string $configKey, string $role, string $label): void
    {
        /** @var array{name?: string|null, email?: string|null, password?: string|null} $cfg */
        $cfg = config("seeding.{$configKey}", []);
        $name = $cfg['name'] ?? null;
        $email = $cfg['email'] ?? null;
        $password = $cfg['password'] ?? null;

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            $this->command?->warn("Skipping {$label} seed: SEED_".strtoupper($configKey).'_EMAIL and SEED_'.strtoupper($configKey).'_PASSWORD must be set.');

            return;
        }

        if (app()->isProduction() && $password === 'Secret123!') {
            throw new \RuntimeException("Refusing to seed the default {$label} password in production. Set a strong SEED_".strtoupper($configKey).'_PASSWORD.');
        }

        Role::firstOrCreate([
            'name' => $role,
            'guard_name' => 'web',
        ]);

        $wasRecentlyCreated = ! User::query()->where('email', $email)->exists();

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => is_string($name) && $name !== '' ? $name : $label,
                'status' => 'ACTIVE',
            ],
        );

        $user->forceFill([
            'name' => is_string($name) && $name !== '' ? $name : $label,
            'password' => $password,
            'status' => 'ACTIVE',
            'must_change_password' => false,
        ])->save();

        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($wasRecentlyCreated) {
            app(ActivityLogService::class)->record(
                ActivityLogAction::UtilisateurCree,
                sprintf('Compte staff %s créé par seeder (%s).', $label, $role),
                is_string($user->id) ? $user->id : null,
                null,
                ['context' => 'seeder', 'config_key' => $configKey, 'role' => $role],
            );
        }

        $this->command?->info("{$label} seeded: {$email} (role: {$role})");
    }
}
