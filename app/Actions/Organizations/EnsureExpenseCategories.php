<?php

namespace App\Actions\Organizations;

use App\Models\ExpenseCategory;
use App\Models\Organization;

/**
 * Categorías de gasto por defecto, si la organización todavía no tiene ninguna.
 */
class EnsureExpenseCategories
{
    public function handle(Organization $organization): void
    {
        if (ExpenseCategory::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->exists()) {
            return;
        }

        foreach (ExpenseCategory::DEFAULTS as $name) {
            ExpenseCategory::query()->create(['organization_id' => $organization->id, 'name' => $name]);
        }
    }
}
