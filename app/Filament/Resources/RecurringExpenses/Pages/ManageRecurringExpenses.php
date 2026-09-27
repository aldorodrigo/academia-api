<?php

namespace App\Filament\Resources\RecurringExpenses\Pages;

use App\Actions\Treasury\ExpenseLedger;
use App\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Models\Expense;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

class ManageRecurringExpenses extends ManageRecords
{
    protected static string $resource = RecurringExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generar los del mes')
                ->icon(Heroicon::OutlinedArrowPath)
                ->authorize('create', Expense::class)
                ->modalSubmitActionLabel('Generar')
                ->schema([
                    DatePicker::make('period')->label('Mes')->format('Y-m-01')->displayFormat('m/Y')
                        ->default(fn () => Filament::getTenant()->today()->startOfMonth()->toDateString())->required()->live(),
                    Text::make(fn (Get $get) => $this->preview($get('period'))),
                ])
                ->action(function (array $data): void {
                    $summary = app(ExpenseLedger::class)->generateRecurring(Filament::getTenant(), CarbonImmutable::parse($data['period']));

                    Notification::make()->success()
                        ->title("Gastos pendientes generados: {$summary['created']}.")
                        ->body($summary['existing'] ? "{$summary['existing']} ya estaban generados." : null)
                        ->send();
                }),
            CreateAction::make(),
        ];
    }

    private function preview(?string $period): string
    {
        if (blank($period)) {
            return '';
        }

        $summary = app(ExpenseLedger::class)->generateRecurring(Filament::getTenant(), CarbonImmutable::parse($period), dryRun: true);

        return "Se van a generar {$summary['created']} gastos pendientes.".($summary['existing'] ? " {$summary['existing']} ya estaban generados." : '');
    }
}
