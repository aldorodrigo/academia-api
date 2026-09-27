<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Tipos de ajuste de un cargo. El orden de aplicación de los descuentos es
 * configurable por organización (organizations.billing.discount_order).
 */
enum AdjustmentType: string implements HasLabel
{
    case Scholarship = 'beca';
    case Siblings = 'hermanos';
    case Agreement = 'convenio';
    case Other = 'otro';

    /** Se aplica al registrar el pago (en la imputación), no al emitir el cargo. */
    case EarlyPayment = 'pronto_pago';

    case LateFee = 'recargo';

    /** Clase suspendida que no se cobra (temporadas por día de entrenamiento). */
    case SuspendedClass = 'clase_suspendida';

    /** @var list<self> */
    public const DISCOUNTS = [self::Scholarship, self::Siblings, self::Agreement, self::Other];

    public function getLabel(): string
    {
        return match ($this) {
            self::Scholarship => 'Beca',
            self::Siblings => 'Hermanos',
            self::Agreement => 'Convenio',
            self::Other => 'Otro descuento',
            self::EarlyPayment => 'Pronto pago',
            self::LateFee => 'Recargo por mora',
            self::SuspendedClass => 'Clase suspendida',
        };
    }
}
