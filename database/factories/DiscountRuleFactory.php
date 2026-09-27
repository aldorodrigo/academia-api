<?php

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Models\DiscountRule;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscountRule>
 */
class DiscountRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Hermanos',
            'type' => DiscountType::Siblings,
            'percent' => 20,
            'fixed_amount' => null,
            'sibling_position' => 2,
            'valid_from' => '2000-01-01',
            'valid_to' => null,
        ];
    }
}
