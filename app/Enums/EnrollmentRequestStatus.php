<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EnrollmentRequestStatus: string implements HasColor, HasLabel
{
    case Pending = 'pendiente';
    case Approved = 'aprobada';
    case Rejected = 'rechazada';
    case Cancelled = 'cancelada';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Por confirmar',
            self::Approved => 'Aprobada',
            self::Rejected => 'No aprobada',
            self::Cancelled => 'Cancelada',
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
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Cancelled => 'gray',
        };
    }
}
