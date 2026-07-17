<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'sexe' => fake()->randomElement(['M', 'F']),
            'email' => fake()->unique()->safeEmail(),
            'phonenumber' => '+229'.fake()->numerify('########'),
            'nationality' => 'BJ',
            'status' => 'ACTIVE',
            'password' => 'password',
            'remember_token' => Str::random(10),
        ];
    }
}
