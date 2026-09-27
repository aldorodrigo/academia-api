<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    public function definition(): array
    {
        $age = fake()->numberBetween(6, 16);

        return [
            'program_id' => Program::factory(),
            'organization_id' => fn (array $attributes) => Program::query()->withoutGlobalScopes()->find($attributes['program_id'])->organization_id,
            'name' => "Sub-{$age} ".fake()->unique()->numberBetween(1, 9999),
            'min_age' => $age - 1,
            'max_age' => $age,
        ];
    }
}
