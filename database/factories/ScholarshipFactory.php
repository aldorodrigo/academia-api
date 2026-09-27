<?php

namespace Database\Factories;

use App\Enums\ScholarshipStatus;
use App\Models\Enrollment;
use App\Models\Scholarship;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Scholarship>
 */
class ScholarshipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'student_id' => fn (array $attributes) => Enrollment::query()->withoutGlobalScopes()->find($attributes['enrollment_id'])->student_id,
            'organization_id' => fn (array $attributes) => Enrollment::query()->withoutGlobalScopes()->find($attributes['enrollment_id'])->organization_id,
            'percent' => 50,
            'reason' => 'Situación económica',
            'valid_from' => '2000-01-01',
            'status' => ScholarshipStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => ScholarshipStatus::Approved, 'decided_at' => now()]);
    }
}
