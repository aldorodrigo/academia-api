<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Cancha '.fake()->unique()->numberBetween(1, 999),
            'address' => null,
        ];
    }
}
