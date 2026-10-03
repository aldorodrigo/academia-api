<?php

namespace App\Support\Organizations;

use App\Models\Organization;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Identificador de la organización: va en la URL del panel, en el header
 * X-Organization y en el link de inscripción. No se cambia después del alta.
 */
class Slug
{
    public const MIN = 3;

    public const MAX = 40;

    /**
     * Palabras que chocan con rutas de la app, del panel o de la API.
     */
    public const RESERVED = [
        'admin', 'api', 'app', 'ayuda', 'configurar', 'crear-cuenta', 'email-verification', 'ingresar', 'inicio',
        'inscripcion', 'inscripciones', 'invitacion', 'login', 'logout', 'new', 'nuevo', 'organizaciones',
        'password-reset', 'plataforma', 'profile', 'register', 'registro', 'soporte', 'www',
    ];

    /**
     * "Club Jakare" → "club-jakare".
     */
    public static function normalize(string $value): string
    {
        return trim(Str::limit(Str::slug($value), self::MAX, ''), '-');
    }

    public static function isAvailable(string $slug): bool
    {
        return mb_strlen($slug) >= self::MIN
            && ! in_array($slug, self::RESERVED, true)
            && ! Organization::withTrashed()->where('slug', $slug)->exists();
    }

    /**
     * Una alternativa libre: club-jakare-2, club-jakare-3…
     */
    public static function suggest(string $slug): string
    {
        $base = $slug === '' ? 'club' : Str::limit($slug, self::MAX - 4, '');
        $base = mb_strlen($base) < self::MIN ? $base.'-club' : $base;

        if (self::isAvailable($base)) {
            return $base;
        }

        for ($n = 2; ; $n++) {
            if (self::isAvailable("{$base}-{$n}")) {
                return "{$base}-{$n}";
            }
        }
    }

    /**
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return [
            'required', 'string', 'min:'.self::MIN, 'max:'.self::MAX,
            'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
            Rule::notIn(self::RESERVED),
            Rule::unique('organizations', 'slug'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $attribute = 'slug'): array
    {
        return [
            "{$attribute}.regex" => 'Solo letras minúsculas, números y guiones.',
            "{$attribute}.not_in" => 'Ese identificador no se puede usar.',
            "{$attribute}.unique" => 'Ese identificador ya está en uso.',
            "{$attribute}.min" => 'Tiene que tener al menos '.self::MIN.' caracteres.',
        ];
    }
}
