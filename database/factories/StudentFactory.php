<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'document' => (string) fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'birth_date' => fake()->dateTimeBetween('-16 years', '-6 years')->format('Y-m-d'),
        ];
    }
}
