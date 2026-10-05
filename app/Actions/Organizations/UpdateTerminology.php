<?php

namespace App\Actions\Organizations;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Support\Onboarding\Templates;
use App\Support\Vocabulary;
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
     * $feminine: formas femeninas de las palabras de persona (student, instructor, guardian) cuando la regla no
     * alcanza; vacía = la de la regla, null = no se tocan.
     *
     * @param  array<string, ?string>  $terms
     * @param  array<string, ?string>|null  $feminine
     */
    public function handle(Organization $organization, array $terms, ?array $feminine = null): Organization
    {
        $typeTerms = Templates::terminologyFor($organization->type ?? OrganizationType::Club);
        // Lo que dicen hoy las pantallas (lo guardado, o el valor por defecto).
        $terminology = array_merge(Organization::DEFAULT_TERMINOLOGY, $organization->terminology ?? []);

        foreach (array_intersect_key($terms, array_flip(self::KEYS)) as $key => $word) {
            $terminology[$key] = filled($word) ? Str::ucfirst(trim($word)) : $typeTerms[$key];
        }

        if ($feminine !== null) {
            $forms = $organization->terminology_feminine ?? [];

            foreach (array_intersect_key($feminine, array_flip(Organization::PERSON_TERMS)) as $key => $word) {
                $word = filled($word) ? Str::ucfirst(trim($word)) : null;
                // Igual a la de la regla: no se guarda (si cambia la palabra, se vuelve a derivar).
                if ($word === null || $word === Vocabulary::feminine($terminology[$key])) {
                    unset($forms[$key]);
                } else {
                    $forms[$key] = $word;
                }
            }

            $organization->terminology_feminine = $forms === [] ? null : $forms;
        }

        $organization->forceFill([
            'terminology' => $terminology,
            'terminology_confirmed_at' => now(),
        ])->save();

        return $organization;
    }
}
