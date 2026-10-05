<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Género de una persona (alumno, personal), opcional: null = sin especificar.
 * Solo sirve para nombrarla bien ("Técnica", "Jugadora"); ver docs/PLAN_GENERO.md.
 */
enum Gender: string implements HasLabel
{
    case Female = 'female';
    case Male = 'male';

    public function label(): string
    {
        return match ($this) {
            self::Female => 'Femenino',
            self::Male => 'Masculino',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * Acepta el valor o lo que se escribe en una planilla ("F", "Mujer", "femenino", "M", "Varón"…).
     * Lo vacío o desconocido queda sin especificar.
     */
    public static function parse(self|string|null $value): ?self
    {
        if ($value instanceof self || $value === null) {
            return $value;
        }

        $value = mb_strtolower(trim($value));

        return self::tryFrom($value) ?? match (true) {
            in_array($value, ['f', 'fem', 'femenino', 'femenina', 'mujer', 'nena', 'niña'], true) => self::Female,
            in_array($value, ['m', 'masc', 'masculino', 'varón', 'varon', 'hombre', 'nene', 'niño'], true) => self::Male,
            default => null,
        };
    }
}
