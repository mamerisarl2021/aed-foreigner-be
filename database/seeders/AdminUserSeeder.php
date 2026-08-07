<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsConfiguredStaffUser;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    use SeedsConfiguredStaffUser;

    public function run(): void
    {
        $this->seedConfiguredStaffUser(
            'admin',
            (string) config('roles.administrateur_plateforme'),
            'Administrator',
        );
    }
}
