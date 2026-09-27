<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MoneyAccountType: string implements HasLabel
{
    case Cash = 'caja';
    case Bank = 'banco';
    case Wallet = 'billetera';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'Caja (efectivo)',
            self::Bank => 'Banco',
            self::Wallet => 'Billetera digital',
        };
    }
}
