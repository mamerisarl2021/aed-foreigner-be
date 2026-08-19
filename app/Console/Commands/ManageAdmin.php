<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\StaffKeycloakAdminException;
use App\Models\User;
use App\Services\Auth\AdminAuthService;
use App\Services\Auth\StaffKeycloakAdminClient;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

class ManageAdmin extends Command
{
    protected $signature = 'manage:admin
                            {action : The action to perform (create/update)}
                            {--name= : The name of the admin}
                            {--email= : The email of the admin}
                            {--password= : The password for the admin}
                            {--password-confirmation= : Confirm the password}';

    protected $description = 'Create or update an administrator account';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $name = $this->option('name');
        $email = $this->option('email');
        $password = $this->option('password');
        $passwordConfirmation = $this->option('password-confirmation');

        if (! is_string($name) || $name === '' || ! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            $this->error('Name, email, and password options are required.');

            return self::FAILURE;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email format.');

            return self::FAILURE;
        }

        if ($password !== $passwordConfirmation) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        if ($action === 'create') {
            return $this->createAdmin($name, $email, $password);
        }

        if ($action === 'update') {
            return $this->updateAdmin($name, $email, $password);
        }

        $this->error('Invalid action. Use "create" or "update".');

        return self::FAILURE;
    }

    protected function createAdmin(string $name, string $email, string $password): int
    {
        if (User::query()->where('email', $email)->exists()) {
            $this->error('An admin with this email already exists.');

            return self::FAILURE;
        }

        $role = (string) config('roles.administrateur_plateforme');
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);

        $admin = User::query()->create([
            'name' => $name,
            'email' => $email,
            'status' => 'ACTIVE',
        ]);
        $admin->forceFill(['password' => $password])->save();
        $admin->syncRoles([$role]);

        if (! $this->syncAdminToKeycloak($admin, $password, lookupEmail: $email)) {
            $admin->roles()->detach();
            $admin->delete();

            return self::FAILURE;
        }

        $this->info("Administrator created successfully (role: {$role}).");

        return self::SUCCESS;
    }

    protected function updateAdmin(string $name, string $email, string $password): int
    {
        $admin = User::query()->where('email', $email)->first();

        if (! $admin) {
            $this->error('No admin found with this email.');

            return self::FAILURE;
        }

        $role = (string) config('roles.administrateur_plateforme');
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);

        if (! $this->syncAdminToKeycloak($admin, $password, lookupEmail: $admin->email, name: $name)) {
            return self::FAILURE;
        }

        $admin->forceFill([
            'name' => $name,
            'password' => $password,
            'status' => 'ACTIVE',
        ])->save();
        $admin->syncRoles([$role]);

        $this->info("Administrator updated successfully (role: {$role}).");

        return self::SUCCESS;
    }

    private function syncAdminToKeycloak(User $admin, string $password, string $lookupEmail, ?string $name = null): bool
    {
        if (! AdminAuthService::staffKeycloakEnabled()) {
            return true;
        }

        $role = (string) config('roles.administrateur_plateforme');

        try {
            app(StaffKeycloakAdminClient::class)->syncUser($lookupEmail, [
                'email' => $admin->email,
                'first_name' => $admin->first_name,
                'name' => $name ?? (string) $admin->name,
                'role' => $role,
                'password' => $password,
            ]);
        } catch (StaffKeycloakAdminException $e) {
            $this->error('Keycloak sync failed: '.$e->getMessage());

            return false;
        }

        return true;
    }
}
