<?php

namespace App\Models;

use App\Enums\MoneyAccountType;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\MoneyAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Caja, banco o billetera. El saldo es la suma de sus movimientos (nunca se edita).
 */
#[Fillable(['organization_id', 'name', 'type', 'transfer_details', 'is_active'])]
class MoneyAccount extends Model
{
    /** @use HasFactory<MoneyAccountFactory> */
    use BelongsToOrganization, HasFactory;

    protected $attributes = ['type' => 'caja', 'is_active' => true];

    protected function casts(): array
    {
        return [
            'type' => MoneyAccountType::class,
            'is_active' => 'boolean',
        ];
    }

    public function balance(): int
    {
        return (int) $this->entries()->sum('amount');
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
