<?php

namespace App\Filament\Support;

use App\Models\Organization;
use Filament\Facades\Filament;

/**
 * Etiquetas del panel según el vocabulario de la organización (term('group') → "Categoría").
 */
class Terms
{
    public static function singular(string $key, string $default): string
    {
        $tenant = Filament::getTenant();

        return mb_strtolower($tenant instanceof Organization ? $tenant->term($key) : $default);
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
     * Plural en español para los términos habituales (categoría → categorías, tutor → tutores).
     */
    public static function pluralize(string $word): string
    {
        return match (true) {
            str_ends_with($word, 'z') => mb_substr($word, 0, -1).'ces',
            preg_match('/[aeiouáéó]$/u', $word) === 1 => $word.'s',
            default => $word.'es',
        };
    }
}
