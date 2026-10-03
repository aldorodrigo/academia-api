<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Clases particulares de un profesor: precio de la clase suelta, duración y reglas de reserva.
 * Sus paquetes y su disponibilidad son por usuario (LessonPack, AvailabilitySlot).
 */
#[Fillable(['organization_id', 'user_id', 'enabled', 'duration_minutes', 'single_price', 'min_notice_minutes', 'days_ahead', 'money_account_id'])]
class LessonProfile extends Model
{
    use BelongsToOrganization;

    protected $attributes = [
        'enabled' => false,
        'duration_minutes' => 60,
        'single_price' => 0,
        'min_notice_minutes' => 120,
        'days_ahead' => 30,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'duration_minutes' => 'integer',
            'single_price' => 'integer',
            'min_notice_minutes' => 'integer',
            'days_ahead' => 'integer',
        ];
    }

    /**
     * El perfil del usuario en la organización (sin guardar si no tiene).
     */
    public static function for(User $user, Organization $organization): self
    {
        return static::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->first()
            ?? new static(['organization_id' => $organization->id, 'user_id' => $user->id]);
    }

    public static function teaches(User $user, Organization $organization): bool
    {
        return static::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('enabled', true)
            ->exists();
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function enabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MoneyAccount, $this>
     */
    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    /**
     * @return HasMany<LessonPack, $this>
     */
    public function packs(): HasMany
    {
        return $this->hasMany(LessonPack::class, 'user_id', 'user_id')->where('is_active', true)->orderBy('classes');
    }

    /**
     * @return HasMany<AvailabilitySlot, $this>
     */
    public function availability(): HasMany
    {
        return $this->hasMany(AvailabilitySlot::class, 'user_id', 'user_id')->orderBy('weekday')->orderBy('starts_at');
    }

    /**
     * La cuenta donde entra lo que cobra: la elegida o la primera activa de la organización.
     */
    public function account(): ?MoneyAccount
    {
        return $this->moneyAccount
            ?? MoneyAccount::query()->withoutGlobalScopes()
                ->where('organization_id', $this->organization_id)
                ->where('is_active', true)
                ->orderBy('id')
                ->first();
    }
}
