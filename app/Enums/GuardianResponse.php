<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Respuesta del tutor a "¿Lo llevás?".
 */
enum GuardianResponse: string implements HasLabel
{
    case Going = 'va';
    case NotGoing = 'no_va';

    public function getLabel(): string
    {
        return match ($this) {
            self::Going => 'Va',
            self::NotGoing => 'No va',
        };
    }
}
