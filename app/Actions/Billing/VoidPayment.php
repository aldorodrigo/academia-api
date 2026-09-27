<?php

namespace App\Actions\Billing;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anula un pago con motivo: sus imputaciones dejan de contar (los cargos vuelven a
 * pendientes, también los cubiertos con su saldo a favor) y se crea el
 * contra-movimiento en la caja o banco. No se borra nada.
 */
class VoidPayment
{
    public function handle(Payment $payment, string $reason, User $by): Payment
    {
        if ($payment->isVoided()) {
            throw ValidationException::withMessages(['reason' => 'El pago ya está anulado.']);
        }

        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => 'Indicá el motivo de la anulación.']);
        }

        return DB::transaction(function () use ($payment, $reason, $by) {
            $payment->update(['voided_at' => now(), 'void_reason' => trim($reason), 'voided_by' => $by->id]);

            $payment->ledgerEntries()
                ->whereNull('reverses_id')
                ->whereDoesntHave('reversedBy')
                ->get()
                ->each(fn (LedgerEntry $entry) => $entry->reverse("Anulación recibo N° {$payment->receiptLabel()}", $by->id));

            return $payment;
        });
    }
}
