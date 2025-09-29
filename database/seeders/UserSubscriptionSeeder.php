<?php

namespace Database\Seeders;

use App\Models\UserSubscription;
use Illuminate\Database\Seeder;

class UserSubscriptionSeeder extends Seeder
{
    public function run()
    {
        // Create 10 sample user subscriptions
        UserSubscription::factory()->count(10)->create([
            'type' => 'EMPLOYEE', // Or 'CITIZEN'
            'status' => 'SENT', // Sample status (you can change this)
            'current' => true,
            // You will need to pass valid `user_id`, `structure_id`, and `user_package_id`
        ]);
    }
}
