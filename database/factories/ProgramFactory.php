<?php

namespace Database\Factories;

use App\Enums\GroupCriterion;
use App\Models\Organization;
use App\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Program>
 */
class ProgramFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Fútbol '.fake()->unique()->numberBetween(1, 9999),
            'group_criterion' => GroupCriterion::BirthYear,
        ];
    }
}
