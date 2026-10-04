<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Controllers\PaymentProofController;
use App\Http\Controllers\ReceiptController;
use App\Models\Charge;
use App\Models\PaymentReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Comprobante de transferencia, como lo ve el tutor.
 *
 * @mixin PaymentReport
 */
class PaymentReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'paid_on' => $this->paid_on->toDateString(),
            'reference' => $this->reference,
            'notes' => $this->notes,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'rejection_reason' => $this->rejection_reason,
            'money_account' => $this->moneyAccount ? ['id' => $this->moneyAccount->id, 'name' => $this->moneyAccount->name] : null,
            'charges' => $this->resource->charges()->map(fn (Charge $charge) => [
                'id' => $charge->id,
                'description' => $charge->description,
                'student_first_name' => $charge->student->first_name,
                'pending_amount' => $charge->pendingAmount(),
            ])->values(),
            'proof_url' => PaymentProofController::signedUrl($this->resource),
            'proof_name' => $this->proof_name,
            'created_at' => $this->created_at->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'receipt_number' => $this->payment?->receiptLabel(),
            'receipt_url' => $this->payment ? ReceiptController::signedUrl($this->payment) : null,
            // Lo registró el club (la captura que le llegó por WhatsApp): quién.
            'registered_by' => $this->registered_by_staff ? $this->user->name : null,
        ];
    }
}
