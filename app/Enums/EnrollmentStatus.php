<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EnrollmentStatus: string implements HasColor, HasLabel
{
    case Pending = 'pendiente';
    case Active = 'activo';
    case Scholarship = 'becado';
    case Suspended = 'suspendido';
    case Withdrawn = 'baja';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Active => 'Activo',
            self::Scholarship => 'Becado',
            self::Suspended => 'Suspendido',
            self::Withdrawn => 'Baja',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Active, self::Scholarship => 'success',
            self::Suspended => 'danger',
            self::Withdrawn => 'gray',
        };
    }
}
