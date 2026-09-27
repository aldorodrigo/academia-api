<?php

namespace App\Models;

use App\Enums\ExpenseStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Gasto. Pagado → movimiento de salida en la cuenta; se anula con motivo
 * (contra-movimiento). Una vez pagado no se edita ni se borra.
 */
#[Fillable(['organization_id', 'expense_category_id', 'supplier_id', 'money_account_id', 'description', 'amount', 'due_on', 'paid_on', 'status', 'attachment', 'recurring_expense_id', 'period', 'voided_at', 'void_reason', 'voided_by', 'created_by'])]
class Expense extends Model
{
    use BelongsToOrganization, LogsActivity;

    protected $attributes = ['status' => 'pendiente'];

    protected static function booted(): void
    {
        static::updating(function (Expense $expense): void {
            // Pendiente → pagado o anulado; pagado → anulado. Nada más cambia después de pagar.
            if ($expense->getOriginal('status') !== ExpenseStatus::Pending
                && array_diff(array_keys($expense->getDirty()), ['status', 'voided_at', 'void_reason', 'voided_by', 'updated_at']) !== []) {
                throw new LogicException('Un gasto pagado no se modifica: anulalo y cargá uno nuevo.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Un gasto no se borra: se anula con motivo.');
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'due_on' => 'date',
            'paid_on' => 'date',
            'period' => 'date',
            'status' => ExpenseStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['description', 'amount', 'status', 'paid_on', 'void_reason'])
            ->logOnlyDirty()
            ->useLogName('treasury');
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
     * @return BelongsTo<RecurringExpense, $this>
     */
    public function recurringExpense(): BelongsTo
    {
        return $this->belongsTo(RecurringExpense::class);
    }

    /**
     * @return MorphMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'source');
    }
}
