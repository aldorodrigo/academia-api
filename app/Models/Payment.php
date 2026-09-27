<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Pago de una familia, imputado a uno o varios cargos. Lo no imputado es saldo a
 * favor. No se edita ni se borra: se anula con motivo (VoidPayment).
 */
#[Fillable(['organization_id', 'family_id', 'guardian_id', 'money_account_id', 'received_on', 'amount', 'method', 'reference', 'receipt_number', 'notes', 'voided_at', 'void_reason', 'voided_by', 'created_by'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToOrganization, HasFactory, LogsActivity;

    private const VOID_FIELDS = ['voided_at', 'void_reason', 'voided_by', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (Payment $payment): void {
            if (array_diff(array_keys($payment->getDirty()), self::VOID_FIELDS) !== []) {
                throw new LogicException('Un pago no se modifica: anulalo y registrá uno nuevo.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Un pago no se borra: se anula con motivo.');
        });
    }

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'amount' => 'integer',
            'method' => PaymentMethod::class,
            'receipt_number' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['amount', 'received_on', 'receipt_number', 'voided_at', 'void_reason'])
            ->logOnlyDirty()
            ->useLogName('billing');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * "000123".
     */
    public function receiptLabel(): string
    {
        return str_pad((string) $this->receipt_number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Saldo a favor que queda de este pago (lo no imputado).
     */
    public function credit(): int
    {
        if ($this->isVoided()) {
            return 0;
        }

        $allocated = $this->relationLoaded('allocations')
            ? $this->allocations->sum('amount')
            : $this->allocations()->sum('amount');

        return max(0, $this->amount - (int) $allocated);
    }

    /**
     * Lo imputado al registrar el pago (lo que dice el recibo).
     *
     * @return Collection<int, PaymentAllocation>
     */
    public function originalAllocations(): Collection
    {
        return $this->allocations->where('from_credit', false)->values();
    }

    /**
     * Saldo a favor que dejó el pago al registrarse (aunque después se haya aplicado).
     */
    public function creditGenerated(): int
    {
        return max(0, $this->amount - (int) $this->originalAllocations()->sum('amount'));
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function notVoided(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->orderBy('id');
    }

    /**
     * @return MorphMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'source');
    }

    /**
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * @return BelongsTo<Guardian, $this>
     */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    /**
     * @return BelongsTo<MoneyAccount, $this>
     */
    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
