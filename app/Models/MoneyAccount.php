<?php

namespace App\Models;

use App\Enums\MoneyAccountType;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\MoneyAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Caja, banco o billetera. El saldo es la suma de sus movimientos (nunca se edita).
 * Con titular (`user_id`) es una caja personal: la plata del club que tiene quien cobra
 * en efectivo desde la app, hasta que la deposita en una cuenta del club.
 */
#[Fillable(['organization_id', 'user_id', 'name', 'type', 'transfer_details', 'is_active'])]
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

    /**
     * Caja personal del usuario en la organización (null si nunca cobró).
     */
    public static function cashBoxOf(User $user, Organization $organization): ?self
    {
        return static::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * Busca o crea la caja personal ("Caja de Juan Pérez").
     */
    public static function ensureCashBoxOf(User $user, Organization $organization): self
    {
        return static::cashBoxOf($user, $organization) ?? static::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'name' => "Caja de {$user->name}",
            'type' => MoneyAccountType::Cash,
        ]);
    }

    public function isCashBox(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Cuentas del club (sin titular): a donde se deposita.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function club(Builder $query): void
    {
        $query->whereNull('user_id');
    }

    /**
     * Cajas personales.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function cashBoxes(Builder $query): void
    {
        $query->whereNotNull('user_id');
    }

    /**
     * Titular de la caja personal.
     *
     * @return BelongsTo<User, $this>
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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
