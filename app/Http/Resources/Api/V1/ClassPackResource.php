<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ClassPack;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Paquete de clases comprado.
 *
 * @mixin ClassPack
 */
class ClassPackResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reserved = $this->reserved();

        return [
            'id' => $this->id,
            'teacher_id' => $this->user_id,
            'student_id' => $this->student_id,
            'classes' => $this->classes,
            'used' => $this->used,
            'reserved' => $reserved,
            'available' => max(0, $this->remaining() - $reserved),
            'price' => $this->price,
            'valid_days' => $this->valid_days,
            'status' => $this->status->value,
            'activated_on' => $this->activated_on?->toDateString(),
            'expires_on' => $this->expires_on?->toDateString(),
            'charge' => $this->charge === null ? null : [
                'id' => $this->charge->id,
                'amount' => $this->charge->final_amount,
                'pending' => $this->charge->isVoided() ? 0 : $this->charge->pendingAmount(),
            ],
        ];
    }
}
