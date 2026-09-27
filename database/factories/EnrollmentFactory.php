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
 * Por defecto todo en la organización del alumno y en una temporada vigente.
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
            // Una temporada vigente de la organización (se crea una del año si no hay).
            'season_id' => function (array $attributes) {
                $organizationId = $this->student($attributes)->organization_id;
                $today = now()->toDateString();

                return Season::query()->withoutGlobalScopes()
                    ->where('organization_id', $organizationId)
                    ->whereDate('starts_on', '<=', $today)
                    ->whereDate('ends_on', '>=', $today)
                    ->orderByDesc('starts_on')
                    ->value('id')
                    ?? Season::factory()->create([
                        'organization_id' => $organizationId,
                        'name' => (string) now()->year,
                        'starts_on' => now()->startOfYear()->toDateString(),
                        'ends_on' => now()->endOfYear()->toDateString(),
                    ])->id;
            },
            'status' => EnrollmentStatus::Active,
            // Inscripto desde el inicio de su temporada.
            'enrolled_on' => fn (array $attributes) => Season::query()->withoutGlobalScopes()->whereKey($attributes['season_id'])->value('starts_on'),
        ];
    }

    private function student(array $attributes): Student
    {
        return Student::query()->withoutGlobalScopes()->findOrFail($attributes['student_id']);
    }
}
