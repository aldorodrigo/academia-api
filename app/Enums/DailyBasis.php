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

    /** Clases que se dieron: sin las suspendidas, con las recuperaciones. Se cobra al cerrar el período. */
    case Taught = 'dictado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Training => 'Días de entrenamiento',
            self::Attendance => 'Clases asistidas',
            self::Taught => 'Clases dictadas',
        };
    }

    /**
     * "5 entrenamientos", "1 clase".
     */
    public function quantityLabel(int $quantity): string
    {
        return match ($this) {
            self::Training => $quantity === 1 ? '1 entrenamiento' : "{$quantity} entrenamientos",
            self::Attendance, self::Taught => $quantity === 1 ? '1 clase' : "{$quantity} clases",
        };
    }
}
