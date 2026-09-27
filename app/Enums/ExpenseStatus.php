<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ExpenseStatus: string implements HasColor, HasLabel
{
    /** Generado por un gasto recurrente, todavía sin pagar. */
    case Pending = 'pendiente';
    case Paid = 'pagado';
    case Voided = 'anulado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente de pago',
            self::Paid => 'Pagado',
            self::Voided => 'Anulado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Paid => 'success',
            self::Voided => 'gray',
        };
    }
}
