<?php

namespace Database\Factories;

use App\Enums\FeeConceptKind;
use App\Models\FeeConcept;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeConcept>
 */
class FeeConceptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Torneo '.fake()->unique()->numberBetween(1, 9999),
            'kind' => FeeConceptKind::OneTime,
        ];
    }
}
