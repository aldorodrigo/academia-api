<?php

namespace Database\Factories;

use App\Models\MedicalRecord;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalRecord>
 */
class MedicalRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'organization_id' => fn (array $attributes) => Student::query()->withoutGlobalScopes()->find($attributes['student_id'])->organization_id,
            'blood_type' => fake()->randomElement(['O+', 'O-', 'A+', 'B+', 'AB+']),
            'allergies' => 'Penicilina',
            'emergency_contact_name' => fake()->name(),
            'emergency_contact_phone' => '0981 '.fake()->numerify('### ###'),
            'fit_until' => now()->addYear()->toDateString(),
        ];
    }
}
