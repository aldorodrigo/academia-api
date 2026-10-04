<?php

namespace App\Models;

use App\Enums\CashDepositStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Depósito (rendición) de la plata de una caja personal a una cuenta del club. Queda por
 * confirmar hasta que quien valida lo confirma (se registra la transferencia) o lo rechaza.
 */
#[Fillable(['organization_id', 'money_account_id', 'user_id', 'to_account_id', 'amount', 'deposited_on', 'reference', 'notes', 'status', 'reviewed_by', 'reviewed_at', 'rejection_reason', 'transfer_id'])]
class CashDeposit extends Model
{
    use BelongsToOrganization, LogsActivity;

    protected $attributes = ['status' => 'pendiente'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'deposited_on' => 'date',
            'status' => CashDepositStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['amount', 'deposited_on', 'to_account_id', 'status', 'rejection_reason'])
            ->logOnlyDirty()
            ->useLogName('billing');
    }

    public function isPending(): bool
    {
        return $this->status === CashDepositStatus::Pending;
    }

    /**
     * Estado para mostrar: un confirmado cuya transferencia se anuló, "Anulado".
     */
    public function displayStatus(): CashDepositStatus
    {
        return $this->status === CashDepositStatus::Confirmed && $this->transfer?->isVoided()
            ? CashDepositStatus::Voided
            : $this->status;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', CashDepositStatus::Pending->value);
    }

    /**
     * Caja personal de la que sale.
     *
     * @return BelongsTo<MoneyAccount, $this>
     */
    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'money_account_id');
    }

    /**
     * @return BelongsTo<MoneyAccount, $this>
     */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'to_account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<Transfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }
}
