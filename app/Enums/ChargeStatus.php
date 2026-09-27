<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Estado calculado de un cargo (no se guarda): ver Charge::status().
 */
enum ChargeStatus: string implements HasColor, HasLabel
{
    case Pending = 'pendiente';
    case Overdue = 'vencido';
    case Paid = 'pagado';
    case Voided = 'anulado';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Overdue => 'Vencido',
            self::Paid => 'Pagado',
            self::Voided => 'Anulado',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Overdue => 'danger',
            self::Paid => 'success',
            self::Voided => 'gray',
        };
    }
}
