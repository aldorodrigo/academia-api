<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pertenencia de un usuario a una organización.
 *
 * No usa BelongsToOrganization: se consulta entre organizaciones
 * para saber a cuáles puede entrar un usuario.
 *
 * `collects_to_org_cash`: lo que cobra en efectivo entra directo a la Caja del club (sin caja
 * personal ni depósito). Por defecto solo quien creó la organización (docs/PLAN_COBRO_EFECTIVO.md §9).
 */
#[Fillable(['organization_id', 'user_id', 'status', 'collects_to_org_cash', 'collects_to_org_cash_changed_by', 'collects_to_org_cash_changed_at'])]
class Membership extends Model
{
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'collects_to_org_cash' => 'boolean',
            'collects_to_org_cash_changed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function of(User $user, Organization $organization): ?self
    {
        return self::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->first();
    }
}
