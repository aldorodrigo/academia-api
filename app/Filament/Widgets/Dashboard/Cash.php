<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Resources\MoneyAccounts\MoneyAccountResource;
use App\Models\MoneyAccount;

/**
 * Caja: el saldo de cada cuenta y el total.
 */
class Cash extends Card
{
    protected string $view = 'filament.widgets.dashboard.cash';

    protected static ?int $sort = 5;

    public static function canView(): bool
    {
        return self::allows('viewAny', MoneyAccount::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $accounts = $this->metrics()->accounts();

        return [
            'accounts' => $accounts,
            'total' => (int) $accounts->sum('balance'),
            'url' => MoneyAccountResource::getUrl('index'),
        ];
    }
}
