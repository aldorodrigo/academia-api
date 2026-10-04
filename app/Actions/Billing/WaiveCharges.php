<?php

namespace App\Actions\Billing;

use App\Models\Charge;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Condonar: perdonar lo que falta pagar de una o varias cuotas (por ejemplo, la deuda de un
 * alumno dado de baja). Lo decide quien tiene el permiso "Condonar deudas" (el admin siempre).
 *
 * Es una anulación marcada como condonación: sale de saldos, morosos e informes como una anulada,
 * con estado "Condonado". Queda quién (`voided_by`), cuándo (`voided_at`), por qué (`void_reason`)
 * y cuánto (`waived_amount`, lo que faltaba pagar); lo ya pagado sigue imputado y es ingreso.
 * La clave del período no se libera (el generador no la vuelve a emitir) y los descuentos de clases
 * suspendidas que ya tenía quedan usados.
 */
class WaiveCharges
{
    public const PERMISSION = 'Waive:Charge';

    public static function allows(?User $user): bool
    {
        return $user?->can(self::PERMISSION) ?? false;
    }

    /**
     * Se puede condonar: no anulada y con algo pendiente.
     */
    public static function canWaive(Charge $charge): bool
    {
        return ! $charge->isVoided() && $charge->pendingAmount() > 0;
    }

    /**
     * @param  iterable<Charge>  $charges
     * @return int total condonado
     */
    public function handle(iterable $charges, string $reason, User $by): int
    {
        if (! self::allows($by)) {
            throw ValidationException::withMessages(['reason' => 'No tenés permiso para condonar deudas.']);
        }

        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => 'Indicá el motivo de la condonación.']);
        }

        /** @var Collection<int, Charge> $charges */
        $charges = collect($charges);

        if ($charges->isEmpty() || $charges->contains(fn (Charge $charge) => ! self::canWaive($charge))) {
            throw ValidationException::withMessages(['reason' => 'Solo se condonan cuotas con algo pendiente (no anuladas ni pagadas).']);
        }

        return DB::transaction(fn () => (int) $charges->sum(function (Charge $charge) use ($reason, $by) {
            $pending = $charge->pendingAmount();

            $charge->update([
                'voided_at' => now(),
                'void_reason' => trim($reason),
                'voided_by' => $by->id,
                'waived_amount' => $pending,
            ]);

            return $pending;
        }));
    }
}
