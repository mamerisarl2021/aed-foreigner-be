<?php

namespace Database\Factories;

use App\Models\Structure;
use App\Models\User;
use App\Models\UserPackage;
use App\Models\UserSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserSubscriptionFactory extends Factory
{
    protected $model = UserSubscription::class;

    public function definition()
    {
        return [
            'user_id' => User::factory(),
            'structure_id' => Structure::factory()->create()->id,
            'type' => $this->faker->randomElement(['EMPLOYEE', 'CITIZEN']),
            'status' => $this->faker->randomElement(['SENT', 'TRAITEDBYSYSTEM', 'REJECTED', 'TRAITEDBYMANAGER', 'TRAITEDBYAGENT']),
            'package_id' => UserPackage::factory()->create()->id,
            'current' => true,
        ];
    }
}
