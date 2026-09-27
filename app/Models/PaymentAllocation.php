<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Parte de un pago aplicada a un cargo (más el pronto pago, si saldó a tiempo).
 * Deja de contar si el pago se anula. Inmutable.
 */
#[Fillable(['organization_id', 'payment_id', 'charge_id', 'amount', 'early_payment_discount', 'early_payment_label'])]
class PaymentAllocation extends Model
{
    use BelongsToOrganization;

    protected static function booted(): void
    {
        static::creating(function (PaymentAllocation $allocation): void {
            $allocation->organization_id ??= $allocation->payment?->organization_id;
        });

        static::updating(fn () => throw new LogicException('Una imputación no se modifica.'));
        static::deleting(fn () => throw new LogicException('Una imputación no se borra: se anula el pago.'));
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'early_payment_discount' => 'integer',
        ];
    }

    /**
     * Lo que cubre del cargo: lo pagado más el pronto pago.
     */
    public function covered(): int
    {
        return $this->amount + $this->early_payment_discount;
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Charge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }
}
