<?php

namespace App\Actions\Organizations;

use App\Enums\FeeConceptKind;
use App\Models\FeeConcept;
use App\Models\Organization;

/**
 * Crea los conceptos de cobro del sistema (cuota mensual e inscripción) que falten.
 * El nombre se puede cambiar; se identifican por `code`.
 */
class EnsureFeeConcepts
{
    public function handle(Organization $organization): void
    {
        $concepts = [
            FeeConcept::MONTHLY_FEE => ['Cuota mensual', FeeConceptKind::Monthly],
            FeeConcept::ENROLLMENT_FEE => ['Inscripción', FeeConceptKind::OneTime],
        ];

        foreach ($concepts as $code => [$name, $kind]) {
            FeeConcept::query()->withoutGlobalScopes()->firstOrCreate(
                ['organization_id' => $organization->id, 'code' => $code],
                ['name' => $name, 'kind' => $kind],
            );
        }
    }
}
