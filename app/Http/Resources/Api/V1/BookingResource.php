<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Booking;
use App\Models\Family;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Reserva de clase particular. Para el profesor (forTeacher) suma el paquete y el saldo a favor.
 *
 * @mixin Booking
 */
class BookingResource extends JsonResource
{
    private bool $forTeacher = false;

    public function forTeacher(): static
    {
        $this->forTeacher = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $usesPack = $this->usesPack();

        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'starts_at' => substr($this->starts_at, 0, 5),
            'ends_at' => substr($this->ends_at, 0, 5),
            'status' => $this->status->value,
            'payment' => $usesPack ? 'paquete' : 'suelta',
            'price' => $usesPack ? null : $this->price,
            'class_pack_id' => $this->class_pack_id,
            'teacher' => ['id' => $this->user_id, 'name' => $this->teacher?->name],
            'student' => [
                'id' => $this->student->id,
                'first_name' => $this->student->first_name,
                'full_name' => $this->student->full_name,
            ],
            'charge' => $this->charge === null ? null : [
                'id' => $this->charge->id,
                'amount' => $this->charge->final_amount,
                'pending' => $this->charge->isVoided() ? 0 : $this->charge->pendingAmount(),
            ],
            'can_cancel' => $this->canBeCancelled(),
            ...($this->forTeacher ? [
                'pack' => $this->classPack === null ? null : new ClassPackResource($this->classPack),
                'credit' => $this->student->family_id === null
                    ? 0
                    : (Family::query()->find($this->student->family_id)?->credit() ?? 0),
            ] : []),
        ];
    }
}
