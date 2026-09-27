<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case Cash = 'efectivo';
    case Transfer = 'transferencia';
    case Wallet = 'billetera';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::Transfer => 'Transferencia',
            self::Wallet => 'Billetera digital',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
