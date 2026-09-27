<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Qué se cobra del período en curso cuando alguien se inscribe cuando ya empezó.
 */
enum MidPeriod: string implements HasLabel
{
    /** La parte que falta (en el cobro por día, los días que faltan). */
    case Prorated = 'proporcional';

    case Full = 'completo';

    /** El período en curso no se cobra. */
    case Next = 'proximo';

    public function getLabel(): string
    {
        return match ($this) {
            self::Prorated => 'Proporcional (lo que falta del período)',
            self::Full => 'Completo',
            self::Next => 'Desde el próximo período',
        };
    }
}
