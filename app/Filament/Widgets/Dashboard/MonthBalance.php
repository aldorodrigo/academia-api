<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Pages\Reports;
use App\Reports\BalanceReport;

/**
 * Balance del mes (ingresos, gastos y saldo): lo que mira la comisión. Permiso "Ver informes".
 */
class MonthBalance extends Card
{
    protected string $view = 'filament.widgets.dashboard.month-balance';

    protected static ?int $sort = 8;

    public static function canView(): bool
    {
        return self::allows('View:Reports');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $today = $this->organization()->today();
        $data = (new BalanceReport($this->organization(), $today->startOfMonth(), $today->endOfMonth()))->data();

        return [
            'income' => $data['income']['total'],
            'expenses' => $data['expenses']['total'],
            'closing' => $data['closing_balance'],
            'month' => $today->locale('es')->translatedFormat('F'),
            'url' => Reports::getUrl(),
        ];
    }
}
