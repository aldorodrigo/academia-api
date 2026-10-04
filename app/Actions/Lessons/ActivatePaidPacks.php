<?php

namespace App\Actions\Lessons;

use App\Enums\ClassPackStatus;
use App\Models\ClassPack;
use App\Models\Family;
use App\Models\Organization;

/**
 * Activa los paquetes pendientes cuyo cargo quedó pagado (después de un pago o de aplicar
 * saldo a favor). El vencimiento corre desde la activación.
 */
class ActivatePaidPacks
{
    /**
     * @return int paquetes activados
     */
    public function forFamily(Family $family): int
    {
        $packs = ClassPack::query()->withoutGlobalScopes()
            ->where('organization_id', $family->organization_id)
            ->where('status', ClassPackStatus::PendingPayment)
            ->whereNotNull('charge_id')
            ->whereHas('student', fn ($query) => $query->withoutGlobalScopes()->where('family_id', $family->id))
            ->with(['charge.allocations.payment'])
            ->get();

        $activated = 0;
        $today = Organization::query()->find($family->organization_id)?->today();

        foreach ($packs as $pack) {
            if ($pack->charge !== null && ! $pack->charge->isVoided() && $pack->charge->pendingAmount() === 0 && $today !== null) {
                $pack->activate($today);
                $activated++;
            }
        }

        return $activated;
    }
}
