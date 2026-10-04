<?php

namespace App\Actions\Billing;

use App\Models\Charge;
use App\Models\ChargeWaiver;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Anula un cargo con motivo (queda en el registro de actividad). No se borra.
 *
 * Con `$reissue`, una cuota de temporada se vuelve a emitir en el momento con los montos,
 * descuentos y becas de hoy (por ejemplo, después de aprobar una beca). Sin eso, el período
 * queda sin cobrar: el generador no la vuelve a crear.
 */
class VoidCharge
{
    public function __construct(private IssueSeasonCharges $issue) {}

    public function handle(Charge $charge, string $reason, ?User $by, bool $reissue = false): Charge
    {
        if ($charge->isVoided()) {
            throw ValidationException::withMessages(['reason' => 'El cargo ya está anulado.']);
        }

        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => 'Indicá el motivo de la anulación.']);
        }

        $reissue = $reissue && self::canReissue($charge);

        $charge->update([
            'voided_at' => now(),
            'void_reason' => trim($reason),
            'voided_by' => $by?->id,
            ...($reissue ? ['unique_key' => null] : []),
        ]);

        // Los descuentos de clases suspendidas que había usado esta cuota quedan pendientes otra vez
        // (entran en la cuota reemitida o en la próxima).
        ChargeWaiver::query()->where('applied_charge_id', $charge->id)->update(['applied_charge_id' => null]);

        if ($reissue) {
            $this->issue->forEnrollment($charge->enrollment, until: $charge->period_start, from: $charge->period_start, createdBy: $by?->id);
        }

        return $charge;
    }

    /**
     * Cuota de una temporada con plan (se puede volver a calcular).
     */
    public static function canReissue(Charge $charge): bool
    {
        return $charge->unique_key !== null && $charge->period_start !== null && $charge->enrollment !== null;
    }
}
