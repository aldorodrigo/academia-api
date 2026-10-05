<?php

namespace App\Actions\Billing;

use App\Models\Membership;
use App\Models\User;

/**
 * "Cobra directo a la Caja" sí/no para una persona (lo decide quien administra los miembros). Queda quién
 * y cuándo lo cambió, también en el registro de actividad. Lo que ya tenía en su caja personal no se
 * mueve: sigue ahí hasta que lo deposite.
 */
class SetCollectsToOrgCash
{
    public function handle(Membership $membership, bool $value, ?User $by): Membership
    {
        if ($membership->collects_to_org_cash === $value) {
            return $membership;
        }

        $membership->forceFill([
            'collects_to_org_cash' => $value,
            'collects_to_org_cash_changed_by' => $by?->id,
            'collects_to_org_cash_changed_at' => now(),
        ])->save();

        activity('billing')->performedOn($membership)->causedBy($by)
            ->withProperties(['user_id' => $membership->user_id, 'organization_id' => $membership->organization_id, 'collects_to_org_cash' => $value])
            ->log($value ? 'Cobra directo a la Caja' : 'Rinde lo que cobra (caja personal)');

        return $membership;
    }
}
