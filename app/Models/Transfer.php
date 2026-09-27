<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * Transferencia entre cuentas: dos movimientos enlazados (sale de una, entra a la
 * otra). No es ingreso ni gasto. Se anula revirtiendo los dos.
 */
#[Fillable(['organization_id', 'from_account_id', 'to_account_id', 'amount', 'transferred_on', 'description', 'voided_at', 'void_reason', 'voided_by', 'created_by'])]
class Transfer extends Model
{
    use BelongsToOrganization;

    protected static function booted(): void
    {
        static::updating(function (Transfer $transfer): void {
            if (array_diff(array_keys($transfer->getDirty()), ['voided_at', 'void_reason', 'voided_by', 'updated_at']) !== []) {
                throw new LogicException('Una transferencia no se modifica: anulala y cargá una nueva.');
            }
        });

        static::deleting(fn () => throw new LogicException('Una transferencia no se borra: se anula con motivo.'));
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'transferred_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * @return BelongsTo<MoneyAccount, $this>
     */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'from_account_id');
    }

    /**
     * @return BelongsTo<MoneyAccount, $this>
     */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'to_account_id');
    }

    /**
     * @return MorphMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'source');
    }
}
