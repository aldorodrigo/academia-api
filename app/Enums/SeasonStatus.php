<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Estado de la temporada según sus fechas (puede haber varias vigentes a la vez).
 */
enum SeasonStatus: string implements HasColor, HasLabel
{
    case Upcoming = 'proxima';
    case Active = 'vigente';
    case Finished = 'finalizada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Upcoming => 'Próxima',
            self::Active => 'Vigente',
            self::Finished => 'Finalizada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Upcoming => 'info',
            self::Active => 'success',
            self::Finished => 'gray',
        };
    }
}
