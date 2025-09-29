<?php

namespace Database\Seeders;

use App\Models\StructureSubscription;
use Illuminate\Database\Seeder;

class StructureSubscriptionSeeder extends Seeder
{
    public function run()
    {
        // Create 10 sample structure subscriptions
        StructureSubscription::factory()->count(10)->create([
            'structure_id' => 1, // Provide valid `structure_id`
            'structure_package_id' => 1, // Provide valid `structure_package_id`
        ]);
    }
}
