<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ManageAdmin extends Command
{
    protected $signature = 'manage:admin
                            {action : The action to perform (create/update)}
                            {--name= : The name of the admin}
                            {--email= : The email of the admin}
                            {--password= : The password for the admin}
                            {--password-confirmation= : Confirm the password}';

    protected $description = 'Create or update an administrator account';

    public function handle()
    {
        $action = $this->argument('action');
        $name = $this->option('name');
        $email = $this->option('email');
        $password = $this->option('password');
        $passwordConfirmation = $this->option('password-confirmation');

        // Ensure all options are provided
        if (! $name || ! $email || ! $password) {
            $this->error('Name, email, and password options are required.');

            return;
        }

        // Validate email format
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email format.');

            return;
        }

        // Confirm the password
        if ($password !== $passwordConfirmation) {
            $this->error('Passwords do not match.');

            return;
        }

        // Handle action (create or update)
        if ($action === 'create') {
            $this->createAdmin($name, $email, $password);
        } elseif ($action === 'update') {
            $this->updateAdmin($name, $email, $password);
        } else {
            $this->error('Invalid action. Use "create" or "update".');
        }
    }

    protected function createAdmin($name, $email, $password)
    {

        // Check if user already exists
        if (User::where('email', $email)->exists()) {
            $this->error('An admin with this email already exists.');

            return;
        }

        $admin = User::create([
            'name' => $name,
            'email' => $email,
        ]);
        $admin->forceFill(['password' => $password])->save();

        // Assign admin role
        if (! $admin->hasRole(config('roles.administrateur_plateforme'))) {
            $admin->assignRole(config('roles.administrateur_plateforme'));
        }

        $this->info('Administrator created successfully.');
    }

    protected function updateAdmin($name, $email, $password)
    {
        // Find admin by email
        $admin = User::where('email', $email)->first();

        if (! $admin) {
            $this->error('No admin found with this email.');

            return;
        }
        $admin->forceFill([
            'name' => $name,
            'password' => $password,
        ])->save();
        $this->info('Administrator updated successfully.');
    }
}
