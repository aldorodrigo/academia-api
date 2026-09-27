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

    /** Pagando hasta el día `until_day` del mes del cargo (se aplica al imputar el pago). */
    case EarlyPayment = 'pronto_pago';

    public function getLabel(): string
    {
        return match ($this) {
            self::Siblings => 'Hermanos',
            self::Agreement => 'Convenio',
            self::Other => 'Otro',
            self::EarlyPayment => 'Pronto pago',
        };
    }

    public function adjustmentType(): AdjustmentType
    {
        return AdjustmentType::from($this->value);
    }
}
