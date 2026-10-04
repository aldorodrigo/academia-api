<?php

namespace App\Support\Onboarding;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\Program;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Cada deporte con lo suyo: una organización que enseña fútbol recibe la propuesta de decir jugador,
 * técnico, categoría y cancha; una de natación, alumno, profesor, nivel y pileta
 * (`Templates::programTerminology`). Manda la primera disciplina elegida que tenga propuesta.
 * Solo se proponen las palabras que siguen como vinieron con el tipo y mientras no haya decidido
 * nada (`terminology_confirmed_at`). La usan `GET onboarding` y la guía del panel.
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

        // La primera elegida: por orden de alta.
        $program = app(CurrentOrganization::class)->run(
            $organization,
            fn () => Program::query()->orderBy('id')->pluck('name'),
        )->first(fn (string $name) => Templates::programTerminology($name) !== null);

        if ($program === null) {
            return null;
        }

        $typeTerms = Templates::terminologyFor($organization->type ?? OrganizationType::Club);
        $current = [];
        $suggested = [];

        foreach (Templates::programTerminology($program) as $key => $word) {
            $now = $organization->term($key);

            if ($now === $typeTerms[$key] && $now !== $word) {
                $current[$key] = $now;
                $suggested[$key] = $word;
            }
        }

        return $suggested === [] ? null : [
            'programs' => [$program],
            'current' => $current,
            'suggested' => $suggested,
        ];
    }
}
