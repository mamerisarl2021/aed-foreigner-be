<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(PermissionsSeeder::class);
        $this->call(UserTableSeeder::class);
        $this->call(StructurePackagesTableSeeder::class);
        $this->call(UserPackagesTableSeeder::class);
    }
}
