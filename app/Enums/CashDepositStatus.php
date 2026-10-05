<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Depósito de efectivo de una caja personal. `Voided` no se guarda: es un depósito
 * confirmado cuya transferencia se anuló después (`CashDeposit::displayStatus()`).
 */
enum CashDepositStatus: string implements HasColor, HasLabel
{
    case Pending = 'pendiente';
    case Confirmed = 'confirmado';
    case Rejected = 'rechazado';
    case Voided = 'anulado';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Por confirmar',
            self::Confirmed => 'Confirmado',
            self::Rejected => 'Rechazado',
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
            self::Confirmed => 'success',
            self::Rejected, self::Voided => 'danger',
        };
    }
}
