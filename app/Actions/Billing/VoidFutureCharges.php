<?php

namespace App\Actions\Billing;

use App\Enums\EnrollmentStatus;
use App\Models\Charge;
use App\Models\Enrollment;

/**
 * Baja o suspensión: anula las cuotas de la inscripción cuyo período todavía no empezó
 * y que no tienen pagos. Libera su clave para volver a emitirlas si se reactiva.
 * Las pagadas (aunque sea en parte) y las del período en curso quedan.
 */
class VoidFutureCharges
{
    /**
     * @return int cuotas anuladas
     */
    public function handle(Enrollment $enrollment): int
    {
        $today = $enrollment->organization->today()->toDateString();
        $reason = $enrollment->status === EnrollmentStatus::Withdrawn ? 'Baja de la inscripción' : 'Suspensión de la inscripción';
        $voided = 0;

        Charge::query()->withoutGlobalScopes()
            ->where('enrollment_id', $enrollment->id)
            ->whereNull('voided_at')
            ->whereDate('period_start', '>', $today)
            ->with(['allocations.payment', 'organization'])
            ->get()
            ->filter(fn (Charge $charge) => $charge->paidAmount() === 0)
            ->each(function (Charge $charge) use ($reason, &$voided) {
                $charge->update([
                    'voided_at' => now(),
                    'void_reason' => $reason,
                    'voided_by' => auth()->id(),
                    'unique_key' => null,
                ]);
                $voided++;
            });

        return $voided;
    }
}
