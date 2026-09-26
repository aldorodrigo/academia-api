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
 */
#[Fillable(['organization_id', 'user_id', 'status'])]
class Membership extends Model
{
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
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
}
