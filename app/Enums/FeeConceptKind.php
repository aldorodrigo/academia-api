<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FeeConceptKind: string implements HasLabel
{
    /** Se cobra todos los meses de la temporada (cuota). */
    case Monthly = 'monthly';

    /** Se cobra una vez (inscripción, torneo, indumentaria…). */
    case OneTime = 'one_time';

    public function getLabel(): string
    {
        return match ($this) {
            self::Monthly => 'Mensual',
            self::OneTime => 'Una vez',
        };
    }
}
