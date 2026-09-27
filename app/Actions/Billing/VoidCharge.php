<?php

namespace App\Actions\Billing;

use App\Models\Charge;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Anula un cargo con motivo (queda en el registro de actividad). No se borra.
 */
class VoidCharge
{
    public function handle(Charge $charge, string $reason, User $by): Charge
    {
        if ($charge->isVoided()) {
            throw ValidationException::withMessages(['reason' => 'El cargo ya está anulado.']);
        }

        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => 'Indicá el motivo de la anulación.']);
        }

        $charge->update([
            'voided_at' => now(),
            'void_reason' => trim($reason),
            'voided_by' => $by->id,
        ]);

        return $charge;
    }
}
