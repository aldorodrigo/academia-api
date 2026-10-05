<?php

namespace App\Support;

use App\Enums\Gender;

/**
 * Concordancia con las palabras del vocabulario del club (Categoría / Grupo, Técnico / Profesora, Aula…)
 * y formas de persona (Jugador → Jugadora). Única fuente de estas reglas en la API: la app recibe el
 * resultado en `GET organization` (`vocabulary`), ver docs/PLAN_GENERO.md.
 *
 * Se mira la primera palabra ("Grupo de entrenamiento" es masculina) y se conservan las mayúsculas.
 */
class Vocabulary
{
    public const MASCULINE = 'm';

    public const FEMININE = 'f';

    /** Género común (el/la atleta): concuerda en masculino salvo que se sepa que es una mujer. */
    public const COMMON = 'c';

    /** Masculinas que terminan en -a. */
    private const MASCULINE_WORDS = ['día', 'mapa', 'programa', 'tema', 'sistema', 'problema', 'idioma', 'clima', 'planeta',
        'esquema', 'drama', 'poema', 'dilema', 'sofá', 'papá'];

    /** Femeninas que no terminan en -a, -dad, -tad, -tud, -ión ni -umbre. */
    private const FEMININE_WORDS = ['clase', 'sede', 'base', 'tarde', 'noche', 'red', 'pared', 'mano', 'foto', 'moto',
        'flor', 'piel', 'imagen', 'calle', 'llave', 'nave', 'madre', 'mamá', 'mujer'];

    /** Masculinas que terminan en -ión. */
    private const MASCULINE_ION = ['avión', 'camión', 'gorrión', 'sarampión', 'aluvión', 'guion', 'guión', 'ion', 'ión'];

    /** De género común (el atleta / la atleta), además de las terminadas en -ista y -nte. */
    private const COMMON_WORDS = ['atleta', 'guía', 'profe', 'coach', 'responsable', 'gimnasta', 'karateca', 'colega',
        'joven', 'miembro', 'sensei', 'tutor/a', 'cónyuge', 'líder', 'referente'];

    /** Terminadas en -ista que no son de persona. */
    private const NOT_COMMON_ISTA = ['pista', 'lista', 'vista', 'revista', 'conquista', 'arista'];

    /** Femeninas que empiezan con a tónica: "el aula", "un aula" (pero "las aulas", "esta aula"). */
    private const STRESSED_A = ['aula', 'área', 'agua', 'ala', 'alma', 'arma', 'acta', 'ancla', 'arpa', 'asa', 'ave',
        'alba', 'aria', 'haba', 'habla', 'hacha', 'hada', 'hambre', 'águila'];

    /** Formas de persona que no salen por regla (masculina → femenina). */
    private const FEMININE_FORMS = ['padre' => 'madre', 'papá' => 'mamá', 'hombre' => 'mujer', 'varón' => 'mujer',
        'jefe' => 'jefa', 'presidente' => 'presidenta', 'vicepresidente' => 'vicepresidenta', 'rey' => 'reina'];

    /** Plurales que cambian el acento. */
    private const PLURALS = ['joven' => 'jóvenes', 'examen' => 'exámenes', 'imagen' => 'imágenes', 'origen' => 'orígenes'];

    /**
     * 'm', 'f' o 'c' (común: atleta, estudiante, docente).
     */
    public static function gender(string $word): string
    {
        $head = self::head($word);

        return match (true) {
            $head === '' => self::MASCULINE,
            in_array($head, self::COMMON_WORDS, true),
            str_ends_with($head, 'ista') && ! in_array($head, self::NOT_COMMON_ISTA, true),
            str_ends_with($head, 'nte') => self::COMMON,
            in_array($head, self::MASCULINE_WORDS, true), in_array($head, self::MASCULINE_ION, true) => self::MASCULINE,
            in_array($head, self::FEMININE_WORDS, true),
            str_ends_with($head, 'a'), str_ends_with($head, 'á'),
            str_ends_with($head, 'dad'), str_ends_with($head, 'tad'), str_ends_with($head, 'tud'),
            str_ends_with($head, 'ión'), str_ends_with($head, 'ion'), str_ends_with($head, 'umbre') => self::FEMININE,
            default => self::MASCULINE,
        };
    }

    /**
     * Concuerda en femenino ("la categoría", "esta aula", "cada una"). Las de género común, no.
     */
    public static function isFeminine(string $word): bool
    {
        return self::gender($word) === self::FEMININE;
    }

    /**
     * La forma que corresponde al género de la palabra: gendered('Grupo', 'otro', 'otra') → "otro".
     */
    public static function gendered(string $word, string $masculine, string $feminine): string
    {
        return self::isFeminine($word) ? $feminine : $masculine;
    }

    /**
     * Concordancia con una persona concreta: por su género si se sabe ("Jugadora inscripta", "la atleta");
     * si no, por el de la palabra.
     */
    public static function agree(string $word, ?Gender $person, string $masculine, string $feminine): string
    {
        return match ($person) {
            Gender::Female => $feminine,
            Gender::Male => $masculine,
            null => self::gendered($word, $masculine, $feminine),
        };
    }

    /**
     * El artículo: "la" (categoría), "el" (grupo, aula), "los"/"las" en plural, "un"/"una" sin $definite.
     */
    public static function article(string $word, bool $plural = false, bool $definite = true, ?Gender $person = null): string
    {
        // Una persona concreta concuerda con su género: "la atleta", "el responsable".
        $feminine = $person !== null ? $person === Gender::Female : self::isFeminine($word);

        if ($plural) {
            return $definite ? ($feminine ? 'las' : 'los') : ($feminine ? 'unas' : 'unos');
        }

        // "el aula", "un aula".
        $masculineArticle = ! $feminine || ($person === null && in_array(self::head($word), self::STRESSED_A, true));

        return $definite ? ($masculineArticle ? 'el' : 'la') : ($masculineArticle ? 'un' : 'una');
    }

    /**
     * "la categoría", "el aula", "los grupos" (en minúscula).
     */
    public static function the(string $word, bool $plural = false, ?Gender $person = null): string
    {
        return self::article($word, $plural, person: $person).' '.mb_strtolower($plural ? self::plural($word) : $word);
    }

    /**
     * "una categoría", "un aula", "un grupo" (en minúscula).
     */
    public static function a(string $word): string
    {
        return self::article($word, definite: false).' '.mb_strtolower($word);
    }

    /**
     * "del grupo", "de la categoría", "del aula", "de los técnicos".
     */
    public static function of(string $word, bool $plural = false, ?Gender $person = null): string
    {
        $article = self::article($word, $plural, person: $person);

        return ($article === 'el' ? 'del' : 'de '.$article).' '.mb_strtolower($plural ? self::plural($word) : $word);
    }

    /**
     * "al técnico", "a la técnica", "a los jugadores".
     */
    public static function to(string $word, bool $plural = false, ?Gender $person = null): string
    {
        $article = self::article($word, $plural, person: $person);

        return ($article === 'el' ? 'al' : 'a '.$article).' '.mb_strtolower($plural ? self::plural($word) : $word);
    }

    /**
     * Plural en español: categoría → categorías, tutor → tutores, salón → salones, luz → luces.
     */
    public static function plural(string $word): string
    {
        return self::mapHead($word, function (string $head): string {
            $lower = mb_strtolower($head);

            if (isset(self::PLURALS[$lower])) {
                return self::PLURALS[$lower];
            }

            return match (true) {
                $lower === '' => $head,
                str_ends_with($lower, 'z') => mb_substr($head, 0, -1).'ces',
                str_contains($lower, '/') => $head,
                preg_match('/[aeiouáéó]$/u', $lower) === 1 => $head.'s',
                // Aguda terminada en consonante: salón → salones, bailarín → bailarines, inglés → ingleses.
                preg_match('/[áéíóú][nsl]$/u', $lower) === 1 => self::unaccentLast($head).'es',
                // Grave o esdrújula terminada en -s o -x: no cambia (lunes, tórax).
                preg_match('/[sx]$/u', $lower) === 1 && preg_match_all('/[aeiouáéíóú]+/u', $lower) > 1 => $head,
                default => $head.'es',
            };
        });
    }

    /**
     * Forma femenina de una palabra de persona: Jugador → Jugadora, Técnico → Técnica, Atleta → Atleta.
     */
    public static function feminine(string $word): string
    {
        // Presidente → Presidenta (aunque las terminadas en -nte sean de género común).
        if (self::gender($word) !== self::MASCULINE && ! isset(self::FEMININE_FORMS[self::head($word)])) {
            return $word;
        }

        return self::mapHead($word, function (string $head): string {
            $lower = mb_strtolower($head);

            return match (true) {
                isset(self::FEMININE_FORMS[$lower]) => self::FEMININE_FORMS[$lower],
                str_ends_with($lower, 'o') => mb_substr($head, 0, -1).'a',
                str_ends_with($lower, 'or') => $head.'a',
                preg_match('/[íóéá]n$|és$/u', $lower) === 1 => self::unaccentLast($head).'a',
                default => $head,
            };
        });
    }

    /**
     * Forma masculina de una palabra de persona: Alumna → Alumno, Profesora → Profesor, Atleta → Atleta.
     */
    public static function masculine(string $word): string
    {
        if (self::gender($word) !== self::FEMININE) {
            return $word;
        }

        return self::mapHead($word, function (string $head): string {
            $lower = mb_strtolower($head);
            $reverse = array_search($lower, self::FEMININE_FORMS, true);

            return match (true) {
                $reverse !== false && $lower !== 'mujer' => $reverse,
                str_ends_with($lower, 'ora') => mb_substr($head, 0, -1),
                str_ends_with($lower, 'a') => mb_substr($head, 0, -1).'o',
                default => $head,
            };
        });
    }

    /**
     * Cómo se nombra a una persona: la forma femenina o masculina si se sabe su género; si no, la palabra
     * tal como la eligió la organización. $feminine: la forma que ajustó la organización (si la regla no alcanza).
     */
    public static function forPerson(string $word, ?Gender $gender, ?string $feminine = null): string
    {
        return match ($gender) {
            Gender::Female => filled($feminine) ? $feminine : self::feminine($word),
            Gender::Male => self::masculine($word),
            null => $word,
        };
    }

    /**
     * Cómo se nombra a un grupo de personas (en plural): femenino solo si son todas mujeres; masculino
     * genérico si hay algún varón; si no se sabe de nadie, la palabra de la organización.
     *
     * @param  iterable<?Gender>  $genders
     */
    public static function forPeople(string $word, iterable $genders, ?string $feminine = null): string
    {
        return self::plural(self::forPerson($word, self::groupGender($genders), $feminine));
    }

    /**
     * El género con que se nombra a un grupo: femenino si son todas mujeres, masculino (genérico) si hay
     * algún varón, null (la palabra de la organización) si no se sabe de nadie o la lista está vacía.
     *
     * @param  iterable<?Gender>  $genders
     */
    public static function groupGender(iterable $genders): ?Gender
    {
        $genders = collect($genders);

        return match (true) {
            $genders->isEmpty() => null,
            $genders->every(fn (?Gender $gender) => $gender === Gender::Female) => Gender::Female,
            $genders->contains(Gender::Male) => Gender::Male,
            default => null,
        };
    }

    /**
     * Todo lo que la app necesita para concordar sin repetir las reglas (`GET organization` → `vocabulary`).
     *
     * @return array<string, string>
     */
    public static function describe(string $word, bool $person = false, ?string $feminine = null): array
    {
        $data = [
            'word' => $word,
            'plural' => self::plural($word),
            'gender' => self::gender($word),
            'article' => self::article($word),
        ];

        if ($person) {
            $female = self::forPerson($word, Gender::Female, $feminine);
            $male = self::masculine($word);
            $data += [
                'feminine' => $female,
                'feminine_plural' => self::plural($female),
                'masculine' => $male,
                'masculine_plural' => self::plural($male),
            ];
        }

        return $data;
    }

    /**
     * Cantidad con la palabra en singular o plural: count(1, 'cuota', 'cuotas') → "1 cuota".
     */
    public static function count(int $count, string $singular, string $plural): string
    {
        return $count.' '.($count === 1 ? $singular : $plural);
    }

    /**
     * "Temporada 2026" sin repetir la palabra si el nombre ya la trae.
     */
    public static function season(string $name): string
    {
        return str_starts_with(mb_strtolower(trim($name)), 'temporada') ? $name : "Temporada {$name}";
    }

    /** La primera palabra, en minúscula. */
    private static function head(string $word): string
    {
        return mb_strtolower(explode(' ', trim($word))[0]);
    }

    /**
     * Aplica $transform a la primera palabra y deja el resto igual, con la mayúscula inicial si la tenía.
     *
     * @param  callable(string): string  $transform
     */
    private static function mapHead(string $word, callable $transform): string
    {
        $parts = explode(' ', trim($word), 2);
        $head = $parts[0];
        $new = $transform($head);

        if ($head !== '' && mb_strtoupper(mb_substr($head, 0, 1)) === mb_substr($head, 0, 1)) {
            $new = mb_strtoupper(mb_substr($new, 0, 1)).mb_substr($new, 1);
        }

        return isset($parts[1]) ? $new.' '.$parts[1] : $new;
    }

    /** Saca el acento de la última vocal acentuada: salón → salon, bailarín → bailarin. */
    private static function unaccentLast(string $word): string
    {
        return preg_replace_callback('/([áéíóú])([^áéíóú]*)$/u', fn (array $m) => strtr($m[1], ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']).$m[2], $word);
    }
}
