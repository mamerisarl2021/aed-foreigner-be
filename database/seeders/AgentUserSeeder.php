<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsConfiguredStaffUser;
use Illuminate\Database\Seeder;

class AgentUserSeeder extends Seeder
{
    use SeedsConfiguredStaffUser;

    public function run(): void
    {
        $this->seedConfiguredStaffUser(
            'agent',
            (string) config('roles.agent'),
            'Agent',
        );
    }
}
