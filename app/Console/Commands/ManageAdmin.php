<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

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
            $this->createAdmin($name, $email, Hash::make($password));
        } elseif ($action === 'update') {
            $this->updateAdmin($name, $email, Hash::make($password));
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

        // Create new admin
        $admin = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        // Assign admin role
        if (! $admin->hasRole('admin')) {
            $admin->assignRole('admin');
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
        $admin->password = $password;

        // Update name and password
        $admin->update([
            'name' => $name,
            'password' => $password,
        ]);
        $this->info('Administrator updated successfully.');
    }
}
