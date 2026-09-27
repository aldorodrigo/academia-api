<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\AdjustmentType;
use App\Models\Charge;
use App\Models\ChargeAdjustment;
use App\Models\PaymentAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Charge
 */
class ChargeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status();

        return [
            'id' => $this->id,
            'student' => ['id' => $this->student->id, 'first_name' => $this->student->first_name],
            'concept' => $this->feeConcept->name,
            'description' => $this->description,
            'period' => $this->period?->format('Y-m'),
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'season' => $this->season ? ['id' => $this->season->id, 'name' => $this->season->name] : null,
            'quantity' => $this->quantity,
            'unit_amount' => $this->unit_amount,
            'is_upcoming' => $this->isUpcoming(),
            'group' => $this->group?->name,
            'issued_on' => $this->issued_on->toDateString(),
            'due_on' => $this->due_on->toDateString(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'base_amount' => $this->base_amount,
            'final_amount' => $this->final_amount,
            'paid_amount' => $this->paidAmount(),
            'pending_amount' => $this->pendingAmount(),
            'adjustments' => [
                ...$this->adjustments->map(fn (ChargeAdjustment $adjustment) => [
                    'type' => $adjustment->type->value,
                    'label' => $adjustment->label,
                    'amount' => $adjustment->amount,
                ]),
                // Pronto pago: se aplicó al pagar (queda en la imputación, no en el cargo).
                ...$this->activeAllocations()
                    ->filter(fn (PaymentAllocation $allocation) => $allocation->early_payment_discount > 0)
                    ->map(fn (PaymentAllocation $allocation) => [
                        'type' => AdjustmentType::EarlyPayment->value,
                        'label' => $allocation->early_payment_label,
                        'amount' => -$allocation->early_payment_discount,
                    ]),
            ],
        ];
    }
}
