<?php

namespace Database\Factories;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Program;
use App\Models\Season;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Por defecto todo en la organización del alumno y en su temporada actual.
 *
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'organization_id' => fn (array $attributes) => $this->student($attributes)->organization_id,
            'group_id' => fn (array $attributes) => Group::factory()->for(
                Program::factory()->state(['organization_id' => $this->student($attributes)->organization_id]),
            ),
            // La temporada actual de la organización (se crea si no hay).
            'season_id' => function (array $attributes) {
                $organizationId = $this->student($attributes)->organization_id;

                return Season::query()->withoutGlobalScopes()
                    ->where('organization_id', $organizationId)
                    ->where('is_current', true)
                    ->value('id')
                    ?? Season::factory()->create(['organization_id' => $organizationId, 'is_current' => true])->id;
            },
            'status' => EnrollmentStatus::Active,
            'enrolled_on' => now()->toDateString(),
        ];
    }

    private function student(array $attributes): Student
    {
        return Student::query()->withoutGlobalScopes()->findOrFail($attributes['student_id']);
    }
}
