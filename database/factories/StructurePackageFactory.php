<?php

namespace Database\Factories;

use App\Models\StructurePackage;
use Illuminate\Database\Eloquent\Factories\Factory;

class StructurePackageFactory extends Factory
{
    protected $model = StructurePackage::class;

    public function definition()
    {
        return [
            'prix' => $this->faker->numberBetween(1000, 10000),  // Generates a random price between 1000 and 10000
            'validity' => $this->faker->numberBetween(30, 365),  // Generates random validity in days (e.g., 30 to 365 days)
            'quantity' => $this->faker->numberBetween(1, 100),   // Generates random quantity (e.g., 1 to 100 items)
            'type' => $this->faker->randomElement(['EPF', 'VID']), // Randomly selects between 'EPF' and 'VID' for type
        ];
    }
}
