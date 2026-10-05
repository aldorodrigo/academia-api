<?php

namespace App\Support;

/**
 * Género de las palabras del vocabulario del club (Categoría / Grupo, Técnico / Profesora…),
 * para que los textos concuerden: "la categoría" / "el grupo", "cada una" / "cada uno".
 */
class Vocabulary
{
    /** Femeninas que no terminan en -a ni en -dad. */
    private const FEMININE = ['clase'];

    public static function isFeminine(string $word): bool
    {
        $word = mb_strtolower(trim($word));

        return in_array($word, self::FEMININE, true)
            || str_ends_with($word, 'a')
            || str_ends_with($word, 'dad');
    }

    /**
     * La forma que corresponde al género de la palabra: gendered('Grupo', 'otro', 'otra') → "otro".
     */
    public static function gendered(string $word, string $masculine, string $feminine): string
    {
        return self::isFeminine($word) ? $feminine : $masculine;
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
}
