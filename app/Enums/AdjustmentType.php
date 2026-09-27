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
    case LateFee = 'recargo';

    /** @var list<self> */
    public const DISCOUNTS = [self::Scholarship, self::Siblings, self::Agreement, self::Other];

    public function getLabel(): string
    {
        return match ($this) {
            self::Scholarship => 'Beca',
            self::Siblings => 'Hermanos',
            self::Agreement => 'Convenio',
            self::Other => 'Otro descuento',
            self::LateFee => 'Recargo por mora',
        };
    }
}
