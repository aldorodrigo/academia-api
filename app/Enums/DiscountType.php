<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Reglas de descuento configurables (las becas van aparte: Scholarship).
 */
enum DiscountType: string implements HasLabel
{
    /** Según la posición del hijo entre los inscriptos de la familia. */
    case Siblings = 'hermanos';

    /** Para jugadores puntuales (ej. convenio con una empresa). */
    case Agreement = 'convenio';

    case Other = 'otro';

    public function getLabel(): string
    {
        return match ($this) {
            self::Siblings => 'Hermanos',
            self::Agreement => 'Convenio',
            self::Other => 'Otro',
        };
    }

    public function adjustmentType(): AdjustmentType
    {
        return AdjustmentType::from($this->value);
    }
}
