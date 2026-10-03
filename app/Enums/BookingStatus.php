<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Estado de una reserva de clase particular.
 */
enum BookingStatus: string implements HasColor, HasLabel
{
    case Confirmed = 'confirmada';
    case Attended = 'asistio';
    case Absent = 'ausente';
    case CancelledByStudent = 'cancelada_alumno';
    case CancelledByTeacher = 'cancelada_profesor';

    public function getLabel(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmada',
            self::Attended => 'Vino',
            self::Absent => 'No vino',
            self::CancelledByStudent => 'Cancelada por el alumno',
            self::CancelledByTeacher => 'Cancelada por el profesor',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Confirmed => 'info',
            self::Attended => 'success',
            self::Absent => 'danger',
            self::CancelledByStudent, self::CancelledByTeacher => 'gray',
        };
    }

    public function isCancelled(): bool
    {
        return $this === self::CancelledByStudent || $this === self::CancelledByTeacher;
    }
}
