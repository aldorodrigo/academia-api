<?php

namespace App\Support\Onboarding;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\Program;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Una academia (o escuela, o comisión) que enseña un deporte de equipo: se le proponen las palabras
 * de deporte (Categoría, Técnico, Cancha) en lugar de las de su tipo (Grupo, Profesor, Sala).
 * Solo las palabras que siguen como vinieron con el tipo y mientras no haya decidido nada
 * (`terminology_confirmed_at`). La usan `GET onboarding` y la guía del panel.
 */
class VocabularySuggestion
{
    /**
     * @return array{programs: list<string>, current: array<string, string>, suggested: array<string, string>}|null
     */
    public static function for(Organization $organization): ?array
    {
        if ($organization->terminology_confirmed_at !== null) {
            return null;
        }

        $programs = app(CurrentOrganization::class)->run(
            $organization,
            fn () => Program::query()->orderBy('name')->pluck('name'),
        )->filter(fn (string $name) => Templates::isSport($name))->values()->all();

        if ($programs === []) {
            return null;
        }

        $typeTerms = Templates::terminologyFor($organization->type ?? OrganizationType::Club);
        $current = [];
        $suggested = [];

        foreach (Templates::sportTerminology() as $key => $word) {
            $now = $organization->term($key);

            if ($now === $typeTerms[$key] && $now !== $word) {
                $current[$key] = $now;
                $suggested[$key] = $word;
            }
        }

        return $suggested === [] ? null : [
            'programs' => $programs,
            'current' => $current,
            'suggested' => $suggested,
        ];
    }
}
