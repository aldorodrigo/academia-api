<?php

namespace App\Support\Onboarding;

use App\Enums\GroupCriterion;
use App\Enums\OrganizationType;
use Illuminate\Support\Str;

/**
 * Sugerencias del alta y de la guía "Primeros pasos": las mismas para el panel y la app.
 */
class Templates
{
    /**
     * Vocabulario según el tipo de organización.
     *
     * @return array<string, string>
     */
    public static function terminologyFor(OrganizationType $type): array
    {
        return match ($type) {
            OrganizationType::Club => ['program' => 'Disciplina', 'group' => 'Categoría', 'student' => 'Jugador', 'instructor' => 'Técnico', 'guardian' => 'Tutor', 'space' => 'Cancha'],
            OrganizationType::Academy => ['program' => 'Disciplina', 'group' => 'Grupo', 'student' => 'Alumno', 'instructor' => 'Profesor', 'guardian' => 'Tutor', 'space' => 'Sala'],
            OrganizationType::School => ['program' => 'Disciplina', 'group' => 'Grupo', 'student' => 'Alumno', 'instructor' => 'Profesor', 'guardian' => 'Tutor', 'space' => 'Aula'],
            OrganizationType::ParentsAssociation => ['program' => 'Actividad', 'group' => 'Grupo', 'student' => 'Alumno', 'instructor' => 'Profesor', 'guardian' => 'Tutor', 'space' => 'Espacio'],
        };
    }

    /**
     * Las palabras de deporte que se proponen a una academia, escuela o comisión que enseña uno.
     *
     * @return array{group: string, instructor: string, space: string}
     */
    public static function sportTerminology(): array
    {
        return ['group' => 'Categoría', 'instructor' => 'Técnico', 'space' => 'Cancha'];
    }

    /**
     * Deportes en los que se dice categoría, técnico y cancha: los de equipo, que se arman por edad.
     *
     * @return list<string>
     */
    public static function sports(): array
    {
        return ['Fútbol', 'Futsal', 'Básquet', 'Vóley', 'Handball', 'Hockey', 'Rugby'];
    }

    /**
     * "Fútbol", "futbol infantil" o "Fútbol 7" son fútbol: sin mayúsculas ni tildes, por la primera palabra.
     */
    public static function isSport(string $program): bool
    {
        $normalize = fn (string $word) => Str::of($word)->ascii()->lower()->trim()->toString();
        $first = Str::before($normalize($program).' ', ' ');

        return in_array($first, array_map($normalize, self::sports()), true);
    }

    /**
     * @return list<array{value: string, label: string, description: string, terminology: array<string, string>}>
     */
    public static function organizationTypes(): array
    {
        $descriptions = [
            OrganizationType::Club->value => ['Club', 'Club o asociación deportiva'],
            OrganizationType::Academy->value => ['Academia', 'Academia de deporte, danza, música o idiomas'],
            OrganizationType::School->value => ['Escuela', 'Escuela de formación o instituto'],
            OrganizationType::ParentsAssociation->value => ['Comisión de padres', 'Comisión de padres o ACE de un colegio'],
        ];

        return collect(OrganizationType::cases())->map(fn (OrganizationType $type) => [
            'value' => $type->value,
            'label' => $descriptions[$type->value][0],
            'description' => $descriptions[$type->value][1],
            'terminology' => self::terminologyFor($type),
        ])->all();
    }

    /**
     * Opciones de "¿Cómo les dicen?" (en singular).
     *
     * @return array<string, list<string>>
     */
    public static function terminologyOptions(): array
    {
        return [
            'student' => ['Jugador', 'Alumno', 'Alumna', 'Atleta'],
            'instructor' => ['Técnico', 'Profesor', 'Profesora', 'Instructor', 'Entrenador'],
            'group' => ['Categoría', 'Grupo', 'Nivel', 'Clase'],
            'space' => ['Cancha', 'Sala', 'Aula', 'Espacio', 'Pileta'],
        ];
    }

    /**
     * Disciplinas frecuentes: los deportes de equipo se arman por edad; el resto, por nivel.
     *
     * @return list<array{name: string, group_criterion: string}>
     */
    public static function programs(): array
    {
        $byAge = self::sports();
        $byLevel = ['Natación', 'Tenis', 'Pádel', 'Danza', 'Patín', 'Gimnasia', 'Artes marciales', 'Ajedrez', 'Música', 'Inglés'];

        return [
            ...array_map(fn (string $name) => ['name' => $name, 'group_criterion' => GroupCriterion::BirthYear->value], $byAge),
            ...array_map(fn (string $name) => ['name' => $name, 'group_criterion' => GroupCriterion::Level->value], $byLevel),
        ];
    }

    /**
     * @return list<string>
     */
    public static function levels(): array
    {
        return ['Inicial', 'Intermedio', 'Avanzado'];
    }

    /**
     * Rango de edades del generador de categorías.
     *
     * @return array{from: int, to: int, span: int}
     */
    public static function ages(): array
    {
        return ['from' => 5, 'to' => 16, 'span' => 2];
    }

    /**
     * Categorías por edad, de las más chicas a las más grandes, alineadas con la de más edad:
     * de 5 a 16 de a 2 años → Sub-6 (5 y 6), Sub-8… Sub-16 (15 y 16).
     *
     * @return list<array{name: string, min_age: int, max_age: int, level: null}>
     */
    public static function groupsByAge(int $from, int $to, int $span): array
    {
        $groups = [];

        for ($max = $to; $max >= $from; $max -= $span) {
            $min = max($max - $span + 1, $from);
            $groups[] = ['name' => "Sub-{$max}", 'min_age' => $min, 'max_age' => $max, 'level' => null];
        }

        return array_reverse($groups);
    }

    /**
     * @param  list<string>  $levels
     * @return list<array{name: string, min_age: null, max_age: null, level: string}>
     */
    public static function groupsByLevel(array $levels): array
    {
        return collect($levels)
            ->map(fn (string $level) => trim($level))
            ->filter()
            ->unique(fn (string $level) => mb_strtolower($level))
            ->map(fn (string $level) => ['name' => $level, 'min_age' => null, 'max_age' => null, 'level' => $level])
            ->values()
            ->all();
    }
}
