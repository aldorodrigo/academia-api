<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ClassStatus: string implements HasColor, HasLabel
{
    case Scheduled = 'programada';
    case Suspended = 'suspendida';

    public function getLabel(): string
    {
        return match ($this) {
            self::Scheduled => 'Programada',
            self::Suspended => 'Suspendida',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Scheduled => 'success',
            self::Suspended => 'danger',
        };
    }
}
