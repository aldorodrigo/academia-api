<?php

namespace App\Actions\Billing;

use App\Models\Family;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Aplica el saldo a favor de la familia (lo no imputado de sus pagos, del más viejo
 * al más nuevo) a sus cargos pendientes más viejos. Sin pronto pago: el pago entró antes.
 */
class ApplyCredit
{
    public function __construct(private RegisterPayment $register) {}

    /**
     * @return int monto aplicado
     */
    public function forFamily(Family $family): int
    {
        return DB::transaction(function () use ($family) {
            $applied = 0;
            $payments = Payment::query()->withoutGlobalScopes()
                ->where('family_id', $family->id)
                ->whereNull('voided_at')
                ->with('allocations')
                ->orderBy('received_on')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(fn (Payment $payment) => $payment->credit() > 0);

            foreach ($payments as $payment) {
                $charges = $this->register->pendingCharges($family);

                foreach ($charges as $charge) {
                    $credit = $payment->fresh('allocations')->credit();
                    if ($credit <= 0) {
                        break;
                    }

                    $amount = min($credit, $charge->pendingAmount());
                    $payment->allocations()->create([
                        'organization_id' => $payment->organization_id,
                        'charge_id' => $charge->id,
                        'amount' => $amount,
                    ]);
                    $applied += $amount;
                }
            }

            return $applied;
        });
    }
}
