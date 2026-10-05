<?php

namespace App\Filament\Support;

use App\Enums\Gender;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Vocabulary;
use Filament\Facades\Filament;

/**
 * Etiquetas del panel según el vocabulario de la organización (term('group') → "Categoría").
 */
class Terms
{
    public static function singular(string $key, string $default): string
    {
        return mb_strtolower(self::tenant()?->term($key) ?? $default);
    }

    /** La organización del panel o, fuera del panel (la API arma los mismos textos), la activa. */
    private static function tenant(): ?Organization
    {
        $tenant = Filament::getTenant() ?? app(CurrentOrganization::class)->get();

        return $tenant instanceof Organization ? $tenant : null;
    }

    public static function plural(string $key, string $default): string
    {
        return self::pluralize(self::singular($key, $default));
    }

    public static function label(string $key, string $default): string
    {
        return ucfirst(self::singular($key, $default));
    }

    /**
     * La forma según el género del término del club: gendered('group', 'Categoría', 'otro', 'otra').
     */
    public static function gendered(string $key, string $default, string $masculine, string $feminine): string
    {
        return Vocabulary::gendered(self::singular($key, $default), $masculine, $feminine);
    }

    /**
     * Cómo se nombra a una persona concreta (en minúscula): person('guardian', 'Tutor', Gender::Female) → "tutora".
     */
    public static function person(string $key, string $default, ?Gender $gender): string
    {
        return mb_strtolower(Vocabulary::forPerson(self::label($key, $default), $gender, self::tenant()?->terminology_feminine[$key] ?? null));
    }

    /**
     * "al tutor", "a la tutora", "a la atleta": a una persona concreta, según su género.
     */
    public static function toPerson(string $key, string $default, ?Gender $gender): string
    {
        return Vocabulary::to(self::person($key, $default, $gender), person: $gender);
    }

    /**
     * "el tutor", "la tutora", "la atleta": una persona concreta, según su género.
     */
    public static function thePerson(string $key, string $default, ?Gender $gender): string
    {
        return Vocabulary::the(self::person($key, $default, $gender), person: $gender);
    }

    /**
     * "la categoría", "el grupo", "el aula" (o en plural: "las categorías").
     */
    public static function the(string $key, string $default, bool $plural = false): string
    {
        return Vocabulary::the(self::singular($key, $default), $plural);
    }

    /**
     * "una categoría", "un grupo", "un aula".
     */
    public static function a(string $key, string $default): string
    {
        return Vocabulary::a(self::singular($key, $default));
    }

    /**
     * "de la categoría", "del grupo", "del aula" (o en plural: "de los técnicos").
     */
    public static function of(string $key, string $default, bool $plural = false): string
    {
        return Vocabulary::of(self::singular($key, $default), $plural);
    }

    /**
     * "a la técnica", "al técnico" (o en plural: "a los jugadores").
     */
    public static function to(string $key, string $default, bool $plural = false): string
    {
        return Vocabulary::to(self::singular($key, $default), $plural);
    }

    /**
     * Qué es la organización activa: "club", "academia", "escuela" o "comisión" (para "del club",
     * "de la academia" con Vocabulary::of).
     */
    public static function organization(): string
    {
        return self::tenant()?->typeNoun() ?? 'club';
    }

    /**
     * Plural en español (Vocabulary::plural): categoría → categorías, tutor → tutores, salón → salones.
     */
    public static function pluralize(string $word): string
    {
        return Vocabulary::plural($word);
    }
}
