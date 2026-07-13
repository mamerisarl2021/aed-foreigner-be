<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserPackagesTableSeeder extends Seeder
{
    public function run()
    {
        DB::table('user_packages')->insert([
            [
                'prix' => 5000,
                'validity' => 1,
                'quantity' => 10,
                'type' => 'EPF',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'prix' => 10000,
                'validity' => 2,
                'quantity' => 10,
                'type' => 'EPF',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'prix' => 15000,
                'validity' => 6,
                'quantity' => 10,
                'type' => 'EPF',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'prix' => 5000,
                'validity' => 1,
                'quantity' => 10,
                'type' => 'TOKEN',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'prix' => 10000,
                'validity' => 2,
                'quantity' => 10,
                'type' => 'TOKEN',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'prix' => 15000,
                'validity' => 6,
                'quantity' => 10,
                'type' => 'TOKEN',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'prix' => 10000,
                'validity' => 3,
                'quantity' => 10,
                'type' => 'VID',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'prix' => 15000,
                'validity' => 3,
                'quantity' => 10,
                'type' => 'VID',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'prix' => 20000,
                'validity' => 3,
                'quantity' => 10,
                'type' => 'VID',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
    }
}
