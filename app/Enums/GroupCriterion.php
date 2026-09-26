<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Cómo se arma un grupo dentro de un programa.
 */
enum GroupCriterion: string implements HasLabel
{
    /** Fútbol: Sub-10, Sub-12… según el año de nacimiento. */
    case BirthYear = 'birth_year';

    /** Pádel, danza: Inicial, Intermedio… */
    case Level = 'level';

    public function label(): string
    {
        return match ($this) {
            self::BirthYear => 'Año de nacimiento',
            self::Level => 'Nivel',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
