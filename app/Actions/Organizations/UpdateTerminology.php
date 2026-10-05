<?php

namespace App\Actions\Organizations;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Support\Onboarding\Templates;
use Illuminate\Support\Str;

/**
 * Cómo les dicen: cambia las palabras indicadas (vacía = la del tipo) y deja el vocabulario
 * confirmado, así no se vuelve a proponer el de deporte. Sin palabras, solo lo confirma
 * ("Dejar como estaba"). La usan la app (`PUT organization/terminology`) y la guía del panel.
 */
class UpdateTerminology
{
    /** Palabras que se pueden cambiar. */
    public const KEYS = ['program', 'group', 'student', 'instructor', 'guardian', 'space'];

    /**
     * @param  array<string, ?string>  $terms
     */
    public function handle(Organization $organization, array $terms): Organization
    {
        $typeTerms = Templates::terminologyFor($organization->type ?? OrganizationType::Club);
        // Lo que dicen hoy las pantallas (lo guardado, o el valor por defecto).
        $terminology = array_merge(Organization::DEFAULT_TERMINOLOGY, $organization->terminology ?? []);

        foreach (array_intersect_key($terms, array_flip(self::KEYS)) as $key => $word) {
            $terminology[$key] = filled($word) ? Str::ucfirst(trim($word)) : $typeTerms[$key];
        }

        $organization->forceFill([
            'terminology' => $terminology,
            'terminology_confirmed_at' => now(),
        ])->save();

        return $organization;
    }
}
