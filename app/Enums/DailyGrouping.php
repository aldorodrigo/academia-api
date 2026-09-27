<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * En el cobro por día: una cuota por día, por semana o por mes.
 */
enum DailyGrouping: string implements HasLabel
{
    case Day = 'dia';
    case Week = 'semana';
    case Month = 'mes';

    public function getLabel(): string
    {
        return match ($this) {
            self::Day => 'Una cuota por día',
            self::Week => 'Una cuota por semana',
            self::Month => 'Una cuota por mes',
        };
    }
}
