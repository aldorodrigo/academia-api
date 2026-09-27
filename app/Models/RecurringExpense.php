<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plantilla de gasto mensual (ej. alquiler de cancha): cada mes genera un gasto
 * pendiente que el tesorero confirma al pagarlo.
 */
#[Fillable(['organization_id', 'expense_category_id', 'supplier_id', 'money_account_id', 'description', 'amount', 'day_of_month', 'starts_on', 'ends_on', 'is_active'])]
class RecurringExpense extends Model
{
    use BelongsToOrganization;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'day_of_month' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Vigente en ese mes.
     */
    public function appliesTo(CarbonInterface $period): bool
    {
        return $this->is_active
            && $this->starts_on->startOfMonth()->lte($period)
            && ($this->ends_on === null || $this->ends_on->gte($period));
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<MoneyAccount, $this>
     */
    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    /**
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
