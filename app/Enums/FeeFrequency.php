<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Cada cuánto se cobra la cuota de una temporada.
 */
enum FeeFrequency: string implements HasLabel
{
    case Monthly = 'mensual';
    case Fortnightly = 'quincenal';
    case Weekly = 'semanal';
    case Daily = 'diaria';

    public function getLabel(): string
    {
        return match ($this) {
            self::Monthly => 'Mensual',
            self::Fortnightly => 'Quincenal',
            self::Weekly => 'Semanal',
            self::Daily => 'Por día',
        };
    }

    /**
     * "Monto por mes", "Monto por día".
     */
    public function amountLabel(): string
    {
        return 'Monto por '.$this->unit();
    }

    /**
     * "mes", "quincena", "semana", "día".
     */
    public function unit(): string
    {
        return match ($this) {
            self::Monthly => 'mes',
            self::Fortnightly => 'quincena',
            self::Weekly => 'semana',
            self::Daily => 'día',
        };
    }

    /**
     * Días hasta el vencimiento que se sugieren (en mensual sale del día de vencimiento de la organización).
     */
    public function defaultDueDays(int $organizationDueDay): int
    {
        return match ($this) {
            self::Monthly => max(0, $organizationDueDay - 1),
            self::Fortnightly, self::Weekly => 3,
            self::Daily => 5,
        };
    }
}
