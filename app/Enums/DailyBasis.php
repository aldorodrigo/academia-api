<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Qué días se cuentan en el cobro por día.
 */
enum DailyBasis: string implements HasLabel
{
    /** Días con horario de la categoría dentro del período. */
    case Training = 'entrenamiento';

    /** Clases a las que asistió (requiere el módulo de asistencia). */
    case Attendance = 'asistencia';

    public function getLabel(): string
    {
        return match ($this) {
            self::Training => 'Días de entrenamiento',
            self::Attendance => 'Clases asistidas',
        };
    }

    /**
     * "5 entrenamientos", "1 clase".
     */
    public function quantityLabel(int $quantity): string
    {
        return match ($this) {
            self::Training => $quantity === 1 ? '1 entrenamiento' : "{$quantity} entrenamientos",
            self::Attendance => $quantity === 1 ? '1 clase' : "{$quantity} clases",
        };
    }
}
