<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Pages\Reports;
use App\Models\Charge;
use App\Support\Phone;
use Carbon\CarbonImmutable;

/**
 * Las familias con más deuda vencida, con su WhatsApp para recordarles.
 */
class Delinquents extends Card
{
    protected string $view = 'filament.widgets.dashboard.delinquents';

    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return self::allows('viewAny', Charge::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'families' => collect($this->metrics()->topDelinquents())->map(fn (array $family) => [
                ...$family,
                'since' => CarbonImmutable::parse($family['oldest_due_on'])->format('d/m'),
                'whatsapp' => $family['phone'] === null ? null : 'https://wa.me/'.Phone::digits($family['phone']),
            ]),
            'url' => self::allows('View:Reports') ? Reports::getUrl(['tab' => 'morosos']) : null,
        ];
    }
}
