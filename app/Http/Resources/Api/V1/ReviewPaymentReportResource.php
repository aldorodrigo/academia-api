<?php

namespace App\Http\Resources\Api\V1;

use App\Actions\Billing\PaymentReportAccess;
use App\Actions\Billing\RegisterPayment;
use App\Models\Charge;
use App\Models\MoneyAccount;
use App\Models\PaymentReport;
use Illuminate\Http\Request;

/**
 * Comprobante para quien valida: suma la familia, quién lo informó, lo que
 * debe hoy y las cuentas donde puede entrar el pago.
 *
 * @mixin PaymentReport
 */
class ReviewPaymentReportResource extends PaymentReportResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'family' => [
                'id' => $this->family->id,
                'name' => $this->family->name,
                'students' => $this->family->students->sortBy('birth_date')->pluck('first_name')->values(),
            ],
            'reported_by' => $this->user->name,
            'pending_balance' => (int) app(RegisterPayment::class)->pendingCharges($this->family)
                ->sum(fn (Charge $charge) => $charge->pendingAmount()),
            'money_accounts' => PaymentReportAccess::paymentAccounts()
                ->map(fn (MoneyAccount $account) => ['id' => $account->id, 'name' => $account->name])->values(),
        ];
    }
}
