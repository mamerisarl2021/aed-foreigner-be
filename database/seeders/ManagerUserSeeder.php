<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsConfiguredStaffUser;
use Illuminate\Database\Seeder;

class ManagerUserSeeder extends Seeder
{
    use SeedsConfiguredStaffUser;

    public function run(): void
    {
        $this->seedConfiguredStaffUser(
            'manager',
            (string) config('roles.manager'),
            'Manager',
        );
    }
}
