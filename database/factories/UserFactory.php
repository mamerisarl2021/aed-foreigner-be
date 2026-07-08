<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition()
    {
        return [
            'name' => $this->faker->name,
            'profile' => $this->faker->imageUrl(), // Generates a random profile image URL
            'phonenumber' => $this->faker->unique()->phoneNumber, // Generates a unique phone number
            'password' => Hash::make('password'), // Hashed default password
            'npi' => $this->faker->unique()->numerify('NPI########'), // Generates a unique NPI number
            'status' => $this->faker->randomElement(['ACTIVE', 'INACTIVE']), // Randomly selects status
            'email' => $this->faker->unique()->safeEmail, // Generates a unique email
            'remember_token' => Str::random(10), // Generates a random remember token
        ];
    }
}
