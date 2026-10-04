<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Estado de un paquete de clases comprado.
 */
enum ClassPackStatus: string implements HasColor, HasLabel
{
    case PendingPayment = 'pendiente_pago';
    case Active = 'activo';

    /** Usó todas las clases. */
    case Finished = 'terminado';
    case Expired = 'vencido';

    public function getLabel(): string
    {
        return match ($this) {
            self::PendingPayment => 'Pendiente de pago',
            self::Active => 'Activo',
            self::Finished => 'Terminado',
            self::Expired => 'Vencido',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PendingPayment => 'warning',
            self::Active => 'success',
            self::Finished => 'gray',
            self::Expired => 'danger',
        };
    }
}
