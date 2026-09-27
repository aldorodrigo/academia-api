<?php

namespace App\Models;

use App\Enums\AdjustmentType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Descuento, beca o recargo aplicado a un cargo. Monto con signo.
 */
#[Fillable(['organization_id', 'charge_id', 'type', 'label', 'amount', 'discount_rule_id', 'scholarship_id'])]
class ChargeAdjustment extends Model
{
    use BelongsToOrganization;

    protected static function booted(): void
    {
        static::creating(function (ChargeAdjustment $adjustment): void {
            $adjustment->organization_id ??= $adjustment->charge?->organization_id;
        });
    }

    protected function casts(): array
    {
        return [
            'type' => AdjustmentType::class,
            'amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Charge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }
}
