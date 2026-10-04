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
     * Deportes de equipo: se arman por edad y se dice jugador, técnico, categoría y cancha.
     *
     * @return list<string>
     */
    public static function sports(): array
    {
        return ['Fútbol', 'Futsal', 'Básquet', 'Vóley', 'Handball', 'Hockey', 'Rugby'];
    }

    /**
     * Las palabras habituales de una disciplina, que se le proponen a la organización (cada deporte con
     * lo suyo); null si no hay una propuesta clara (danza, música, ajedrez…). "Fútbol", "futbol infantil"
     * o "Fútbol 7" son fútbol: sin mayúsculas ni tildes, por la primera palabra.
     *
     * @return array{student: string, instructor: string, group: string, space: string}|null
     */
    public static function programTerminology(string $program): ?array
    {
        $normalize = fn (string $word) => Str::of($word)->ascii()->lower()->squish()->toString();
        $name = $normalize($program);

        // Tenis de mesa no se juega en una cancha.
        if (str_starts_with($name, 'tenis de mesa')) {
            return null;
        }

        $team = ['student' => 'Jugador', 'instructor' => 'Técnico', 'group' => 'Categoría', 'space' => 'Cancha'];
        $court = ['student' => 'Alumno', 'instructor' => 'Profesor', 'group' => 'Nivel', 'space' => 'Cancha'];
        $byProgram = [
            ...array_fill_keys(array_map($normalize, self::sports()), $team),
            'natacion' => ['student' => 'Alumno', 'instructor' => 'Profesor', 'group' => 'Nivel', 'space' => 'Pileta'],
            'tenis' => $court,
            'padel' => $court,
        ];

        return $byProgram[Str::before($name.' ', ' ')] ?? null;
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
     * Qué nombra cada palabra del vocabulario ("¿Cómo les dicen…?").
     *
     * @return array<string, string>
     */
    public static function termQuestions(): array
    {
        return [
            'program' => 'A lo que enseñan',
            'student' => 'A los que aprenden',
            'instructor' => 'A quienes enseñan',
            'group' => 'A los grupos',
            'space' => 'Al lugar de la clase',
            'guardian' => 'A los responsables de cada uno',
        ];
    }

    /**
     * Opciones de "¿Cómo les dicen?" (en singular).
     *
     * @return array<string, list<string>>
     */
    public static function terminologyOptions(): array
    {
        return [
            'program' => ['Disciplina', 'Actividad', 'Deporte', 'Taller'],
            'student' => ['Jugador', 'Alumno', 'Alumna', 'Atleta'],
            'instructor' => ['Técnico', 'Profesor', 'Profesora', 'Instructor', 'Entrenador'],
            'group' => ['Categoría', 'Grupo', 'Nivel', 'Clase'],
            'space' => ['Cancha', 'Sala', 'Aula', 'Espacio', 'Pileta'],
            'guardian' => ['Tutor', 'Responsable', 'Encargado'],
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
