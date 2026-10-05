<?php

namespace App\Actions\Billing;

use App\Models\Charge;
use App\Models\ChargeCondonation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deshacer una condonación (mismo permiso que condonar): la cuota vuelve a quedar pendiente por
 * lo que se había condonado. Queda quién, cuándo y por qué en la condonación, que no se borra.
 */
class UnwaiveCharge
{
    public function handle(Charge $charge, string $reason, User $by): Charge
    {
        WaiveCharges::authorize($by);

        if (! $charge->isWaived()) {
            throw ValidationException::withMessages(['reason' => 'La cuota no está condonada.']);
        }

        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => 'Indicá por qué se deshace la condonación.']);
        }

        return DB::transaction(function () use ($charge, $reason, $by) {
            $charge->update(['voided_at' => null, 'void_reason' => null, 'voided_by' => null, 'waived_amount' => null]);

            $charge->condonations()->whereNull('undone_at')->get()
                ->each(fn (ChargeCondonation $condonation) => $condonation->update([
                    'undone_at' => now(),
                    'undone_by' => $by->id,
                    'undo_reason' => trim($reason),
                ]));

            return $charge->refresh();
        });
    }
}
