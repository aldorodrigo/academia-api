<?php

namespace Database\Factories;

use App\Models\FeeConcept;
use App\Models\Season;
use App\Models\Tariff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pasar fee_concept_id y season_id de la misma organización.
 *
 * @extends Factory<Tariff>
 */
class TariffFactory extends Factory
{
    public function definition(): array
    {
        return [
            'fee_concept_id' => FeeConcept::factory(),
            'organization_id' => fn (array $attributes) => FeeConcept::query()->withoutGlobalScopes()->find($attributes['fee_concept_id'])->organization_id,
            'season_id' => fn (array $attributes) => Season::factory()->state(['organization_id' => $attributes['organization_id']]),
            'group_id' => null,
            'amount' => 150000,
            'valid_from' => fn (array $attributes) => Season::query()->withoutGlobalScopes()->find($attributes['season_id'])->starts_on,
        ];
    }
}
