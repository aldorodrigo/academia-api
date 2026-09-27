<?php

namespace Database\Factories;

use App\Enums\MoneyAccountType;
use App\Models\MoneyAccount;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MoneyAccount>
 */
class MoneyAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Banco '.fake()->unique()->numberBetween(1, 9999),
            'type' => MoneyAccountType::Bank,
        ];
    }
}
