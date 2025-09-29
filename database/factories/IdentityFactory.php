<?php

namespace Database\Factories;

use App\Models\Identity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class IdentityFactory extends Factory
{
    protected $model = Identity::class;

    public function definition(): array
    {
        return [
            'type' => $this->faker->randomElement(['ONLINE', 'IN_PERSON']),
            'level' => $this->faker->randomElement(['SIMPLE', 'ADVANCED']),
            'proof' => json_encode([
                'selfiePath' => '',
                'rectoPath' => '',
                'versoPath' => '',
            ]),
            'user_id' => User::factory(),
            'status' => 'PENDING',
        ];
    }

    public function onlineSimple(): self
    {
        return $this->state(fn () => [
            'type' => 'ONLINE',
            'level' => 'SIMPLE',
        ]);
    }

    public function advancedOnline(): self
    {
        return $this->state(fn () => [
            'type' => 'ONLINE',
            'level' => 'ADVANCED',
        ]);
    }

    public function inPerson(): self
    {
        return $this->state(fn () => [
            'type' => 'IN_PERSON',
        ]);
    }
}
