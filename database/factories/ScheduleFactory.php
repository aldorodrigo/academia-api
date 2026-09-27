<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Schedule>
 */
class ScheduleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'organization_id' => fn (array $attributes) => Group::query()->withoutGlobalScopes()->find($attributes['group_id'])->organization_id,
            'weekday' => fake()->numberBetween(1, 5),
            'starts_at' => '17:00',
            'ends_at' => '18:30',
        ];
    }
}
