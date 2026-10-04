<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AttendanceStatus: string implements HasColor, HasLabel
{
    case Present = 'presente';
    case Absent = 'ausente';
    case Justified = 'justificado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Present => 'Presente',
            self::Absent => 'Ausente',
            self::Justified => 'Justificado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Present => 'success',
            self::Absent => 'danger',
            self::Justified => 'warning',
        };
    }
}
