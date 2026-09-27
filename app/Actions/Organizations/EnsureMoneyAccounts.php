<?php

namespace App\Actions\Organizations;

use App\Enums\MoneyAccountType;
use App\Models\MoneyAccount;
use App\Models\Organization;

/**
 * Toda organización tiene al menos una "Caja" para registrar cobros en efectivo.
 */
class EnsureMoneyAccounts
{
    public function handle(Organization $organization): void
    {
        $exists = MoneyAccount::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->exists();

        if (! $exists) {
            MoneyAccount::query()->create(['organization_id' => $organization->id, 'name' => 'Caja', 'type' => MoneyAccountType::Cash]);
        }
    }
}
