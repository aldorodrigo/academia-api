<?php

namespace Database\Factories;

use App\Models\Charge;
use App\Models\FeeConcept;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Cargo manual del alumno (concepto "Cuota mensual" de su organización).
 *
 * @extends Factory<Charge>
 */
class ChargeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'organization_id' => fn (array $attributes) => Student::query()->withoutGlobalScopes()->find($attributes['student_id'])->organization_id,
            'fee_concept_id' => fn (array $attributes) => FeeConcept::query()->withoutGlobalScopes()
                ->where('organization_id', $attributes['organization_id'])->where('code', FeeConcept::MONTHLY_FEE)->value('id'),
            'description' => 'Cuota',
            'base_amount' => 150000,
            'final_amount' => 150000,
            'issued_on' => now()->toDateString(),
            'due_on' => now()->addDays(10)->toDateString(),
        ];
    }
}
