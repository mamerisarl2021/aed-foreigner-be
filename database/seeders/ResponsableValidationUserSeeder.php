<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsConfiguredStaffUser;
use Illuminate\Database\Seeder;

class ResponsableValidationUserSeeder extends Seeder
{
    use SeedsConfiguredStaffUser;

    public function run(): void
    {
        $this->seedConfiguredStaffUser(
            'responsable',
            (string) config('roles.responsable_de_validation'),
            'Responsable de validation',
        );
    }
}
