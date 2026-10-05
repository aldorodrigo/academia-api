<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CashDeposit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Depósito de efectivo de una caja personal a una cuenta del club.
 *
 * @mixin CashDeposit
 */
class CashDepositResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->resource->displayStatus();

        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'deposited_on' => $this->deposited_on->toDateString(),
            'money_account' => ['id' => $this->toAccount->id, 'name' => $this->toAccount->name],
            'reference' => $this->reference,
            'notes' => $this->notes,
            'status' => $status->value,
            'status_label' => $status->label(),
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'holder' => ['id' => $this->user->id, 'name' => $this->user->name],
        ];
    }
}
