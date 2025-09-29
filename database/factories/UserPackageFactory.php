<?php

namespace Database\Factories;

use App\Models\UserPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserPackageFactory extends Factory
{
    protected $model = UserPackage::class;

    public function definition()
    {
        return [
            'prix' => $this->faker->numberBetween(1000, 10000),  // Generates a random price between 1000 and 10000
            'validity' => $this->faker->numberBetween(30, 365),  // Generates a random validity between 30 and 365 days
        ];
    }
}
