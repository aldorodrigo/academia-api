<?php

namespace App\Filament\Pages;

use App\Reports\BalanceReport;
use App\Reports\DelinquentsReport;
use App\Reports\FamilyBalancesReport;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Informes: balance del período, saldos por familia y morosos, con PDF y Excel.
 * Los mismos que ve la comisión en la app (permiso "Ver informes").
 */
class Reports extends Page
{
    protected string $view = 'filament.pages.reports';

    protected static ?string $slug = 'informes';

    protected static ?string $title = 'Informes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 11;

    #[Url]
    public string $tab = 'balance';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    #[Url]
    public int $minMonths = 1;

    /** Morosos: '' todos, 'only' solo dados de baja, 'exclude' sin dados de baja. */
    #[Url]
    public string $withdrawn = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('View:Reports') ?? false;
    }

    public function mount(): void
    {
        $today = Filament::getTenant()->today();
        $this->from ??= $today->startOfMonth()->toDateString();
        $this->to ??= $today->endOfMonth()->toDateString();
    }

    /**
     * @return array{data: array<string, mixed>, links: array{pdf_url: string, xlsx_url: string}}
     */
    public function report(): array
    {
        $organization = Filament::getTenant();

        $report = match ($this->tab) {
            'saldos' => new FamilyBalancesReport($organization),
            'morosos' => new DelinquentsReport($organization, max(1, $this->minMonths), $this->withdrawn ?: null),
            default => new BalanceReport($organization, CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to)),
        };

        return ['data' => $report->data(), 'links' => $report->links()];
    }

    public function money(int $amount): string
    {
        return Money::pyg($amount)->format();
    }
}
