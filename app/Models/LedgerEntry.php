<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Movimiento del libro mayor. Inmutable: no se edita ni se borra; se anula con un
 * contra-movimiento (reverses_id apunta al anulado).
 */
#[Fillable(['organization_id', 'money_account_id', 'occurred_on', 'amount', 'description', 'source_type', 'source_id', 'reverses_id', 'created_by'])]
class LedgerEntry extends Model
{
    use BelongsToOrganization;

    protected static function booted(): void
    {
        static::creating(function (LedgerEntry $entry): void {
            $entry->organization_id ??= $entry->account?->organization_id;
        });

        static::updating(function (): void {
            throw new LogicException('Un movimiento no se modifica: se anula con un contra-movimiento.');
        });

        static::deleting(function (): void {
            throw new LogicException('Un movimiento no se borra: se anula con un contra-movimiento.');
        });
    }

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'amount' => 'integer',
        ];
    }

    /**
     * Contra-movimiento que anula este movimiento.
     */
    public function reverse(string $description, ?int $createdBy = null): self
    {
        return static::query()->create([
            'organization_id' => $this->organization_id,
            'money_account_id' => $this->money_account_id,
            'occurred_on' => now()->toDateString(),
            // El monto guardado (no el que haya en memoria).
            'amount' => -(int) $this->getOriginal('amount'),
            'description' => $description,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'reverses_id' => $this->id,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * @return BelongsTo<MoneyAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'money_account_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<LedgerEntry, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    /**
     * @return HasOne<LedgerEntry, $this>
     */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }
}
