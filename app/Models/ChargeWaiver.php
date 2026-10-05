<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Descuento de una clase suspendida cuya cuota ya tenía pagos: se aplica como ajuste
 * en la próxima cuota del alumno (applied_charge_id). Al deshacer la suspensión se archiva (soft delete).
 */
#[Fillable(['organization_id', 'class_session_id', 'enrollment_id', 'student_id', 'amount', 'label', 'applied_charge_id'])]
class ChargeWaiver extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /**
     * @return BelongsTo<ClassSession, $this>
     */
    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class);
    }

    /**
     * @return BelongsTo<Charge, $this>
     */
    public function appliedCharge(): BelongsTo
    {
        return $this->belongsTo(Charge::class, 'applied_charge_id');
    }
}
