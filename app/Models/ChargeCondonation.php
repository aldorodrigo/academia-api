<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Condonación de una cuota (WaiveCharges): cuánto, por qué y quién. Si se deshace (UnwaiveCharge)
 * queda quién, cuándo y por qué. No se borra.
 */
#[Fillable(['organization_id', 'charge_id', 'student_id', 'amount', 'reason', 'created_by', 'undone_at', 'undone_by', 'undo_reason'])]
class ChargeCondonation extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'undone_at' => 'datetime'];
    }

    public function isUndone(): bool
    {
        return $this->undone_at !== null;
    }

    /**
     * @return BelongsTo<Charge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function undoneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }
}
