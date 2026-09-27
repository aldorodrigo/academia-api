<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Controllers\ReceiptController;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receiptLabel(),
            'received_on' => $this->received_on->toDateString(),
            'amount' => $this->amount,
            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'voided' => $this->isVoided(),
            'receipt_url' => ReceiptController::signedUrl($this->resource),
            'allocations' => $this->allocations->map(fn (PaymentAllocation $allocation) => [
                'charge_id' => $allocation->charge_id,
                'description' => $allocation->charge->description,
                'student_first_name' => $allocation->charge->student->first_name,
                'amount' => $allocation->amount,
            ])->values(),
            'credit_generated' => $this->credit(),
        ];
    }
}
