<?php

namespace App\Filament\Resources\MoneyAccounts\Pages;

use App\Filament\Resources\MoneyAccounts\MoneyAccountResource;
use App\Support\Money;
use Filament\Resources\Pages\ViewRecord;

class ViewMoneyAccount extends ViewRecord
{
    protected static string $resource = MoneyAccountResource::class;

    public function getSubheading(): ?string
    {
        return 'Saldo: '.Money::pyg($this->getRecord()->balance())->format();
    }
}
