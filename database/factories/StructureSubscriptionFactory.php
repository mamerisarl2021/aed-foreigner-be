<?php

namespace Database\Factories;

use App\Models\StructureSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class StructureSubscriptionFactory extends Factory
{
    protected $model = StructureSubscription::class;

    public function definition()
    {
        return [
            'structure_id' => \App\Models\Structure::factory()->create()->id, // Assuming you have a Structure factory
            'structure_package_id' => \App\Models\StructurePackage::factory()->create()->id, // Assuming you have a StructurePackage factory
        ];
    }
}
