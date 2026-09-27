<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Charge;
use App\Models\ChargeAdjustment;
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
            'group' => $this->group?->name,
            'issued_on' => $this->issued_on->toDateString(),
            'due_on' => $this->due_on->toDateString(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'base_amount' => $this->base_amount,
            'final_amount' => $this->final_amount,
            'adjustments' => $this->adjustments->map(fn (ChargeAdjustment $adjustment) => [
                'type' => $adjustment->type->value,
                'label' => $adjustment->label,
                'amount' => $adjustment->amount,
            ])->values(),
        ];
    }
}
