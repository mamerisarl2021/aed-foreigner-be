<?php

namespace Database\Factories;

use App\Models\Structure;
use App\Models\User; // Import the User model as manager
use Illuminate\Database\Eloquent\Factories\Factory;

class StructureFactory extends Factory
{
    protected $model = Structure::class;

    public function definition()
    {
        return [
            'name' => $this->faker->company, // Generate a random company name
            'ifu' => $this->faker->unique()->numerify('IFU#######'), // Generate a unique IFU
            'searchbase' => $this->faker->word, // Generate a random word for the search base
            'manager_id' => User::factory(), // Generate a related user as the manager
            'status' => $this->faker->randomElement(['APPROVED', 'REJECTED', 'PENDING']), // Random status
        ];
    }
}
