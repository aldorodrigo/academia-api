<?php

namespace Database\Factories;

use App\Enums\FeeFrequency;
use App\Models\Organization;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Por defecto: temporada anual sin plan de cobro (no genera cuotas).
 *
 * @extends Factory<Season>
 */
class SeasonFactory extends Factory
{
    public function definition(): array
    {
        $year = fake()->unique()->numberBetween(2030, 2060);

        return [
            'organization_id' => Organization::factory(),
            'name' => (string) $year,
            'kind' => 'anual',
            'starts_on' => "{$year}-01-01",
            'ends_on' => "{$year}-12-31",
        ];
    }

    /**
     * Cuota mensual, creada al empezar cada mes, que vence el día 10.
     */
    public function monthly(): static
    {
        return $this->state(['fee_frequency' => FeeFrequency::Monthly, 'due_days' => 9]);
    }
}
