<?php

namespace Database\Factories;

use App\Models\UserSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserSubscriptionFactory extends Factory
{
    protected $model = UserSubscription::class;

    public function definition()
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'structure_id' => \App\Models\Structure::factory()->create()->id,
            'type' => $this->faker->randomElement(['EMPLOYEE', 'CITIZEN']),
            'status' => $this->faker->randomElement(['SENT', 'TRAITEDBYSYSTEM', 'REJECTED', 'TRAITEDBYMANAGER', 'TRAITEDBYAGENT']),
            'package_id' => \App\Models\UserPackage::factory()->create()->id,
            'current' => true,
        ];
    }
}
