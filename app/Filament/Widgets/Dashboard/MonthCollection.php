<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Resources\Charges\ChargeResource;
use App\Models\Charge;

/**
 * Cobranza del mes: cobrado, lo que vence en el mes y falta, lo vencido y lo que vence esta semana.
 */
class MonthCollection extends Card
{
    protected string $view = 'filament.widgets.dashboard.month-collection';

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return self::allows('viewAny', Charge::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $collection = $this->metrics()->collection();

        return [
            ...$collection,
            'week' => $this->metrics()->dueThisWeek(),
            'month' => $this->organization()->today()->locale('es')->translatedFormat('F'),
            'url' => ChargeResource::getUrl('index'),
        ];
    }
}
