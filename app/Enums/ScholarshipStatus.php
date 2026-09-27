<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ScholarshipStatus: string implements HasColor, HasLabel
{
    case Pending = 'pendiente';
    case Approved = 'aprobada';
    case Rejected = 'rechazada';
    case Revoked = 'revocada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Approved => 'Aprobada',
            self::Rejected => 'Rechazada',
            self::Revoked => 'Revocada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected, self::Revoked => 'gray',
        };
    }
}
