<?php

namespace App\Filament\Resources\Seasons\Support;

use App\Enums\FeeFrequency;
use App\Filament\Support\Terms;
use App\Models\FeeConcept;
use App\Models\Group;
use App\Models\Season;
use App\Models\Tariff;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;

/**
 * Acciones de la temporada: completar el plan de cobro de una temporada creada sin él
 * y cargar un monto nuevo desde una fecha.
 */
class SeasonActions
{
    /**
     * Pasos 2 a 4 del asistente, para quien puede cargar tarifas.
     */
    public static function configurePlan(): Action
    {
        return Action::make('configurePlan')
            ->label('Configurar cobro')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('warning')
            ->visible(fn (Season $record) => ! $record->hasFeePlan() && SeasonPlanSteps::canPlan())
            ->slideOver()
            ->modalHeading(fn (Season $record) => "Cobro de {$record->name}")
            ->fillForm(fn (Season $record) => [
                ...SeasonPlan::planFor($record->kind, Filament::getTenant()),
                'name' => $record->name,
                'kind' => $record->kind->value,
                'starts_on' => $record->starts_on->toDateString(),
                'ends_on' => $record->ends_on->toDateString(),
                'program_ids' => $record->programs()->pluck('programs.id')->all(),
                'has_group_amounts' => false,
                'group_amounts' => [],
            ])
            ->steps([
                ...SeasonPlanSteps::planSteps(),
                Step::make('Revisar')->schema(SeasonPlanSteps::review()),
            ])
            ->action(function (Season $record, array $data): void {
                $record->update([
                    'fee_frequency' => $data['fee_frequency'],
                    'daily_basis' => $data['fee_frequency'] === FeeFrequency::Daily->value ? ($data['daily_basis'] ?? null) : null,
                    'daily_grouping' => $data['fee_frequency'] === FeeFrequency::Daily->value ? ($data['daily_grouping'] ?? null) : null,
                    'due_days' => (int) $data['due_days'],
                    'issue_upfront' => SeasonPlanSteps::canIssueUpfront() && filter_var($data['issue_upfront'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'mid_period' => $data['mid_period'],
                ]);
                SeasonPlan::saveTariffs($record, $data);

                Notification::make()->success()->title('Cobro configurado.')
                    ->body('Las cuotas de los inscriptos se crean con el próximo "Generar cuotas" (o solas, cada día).')
                    ->send();
            });
    }

    /**
     * Monto nuevo desde una fecha (nueva tarifa). Las cuotas ya emitidas no cambian.
     */
    public static function changeAmount(): Action
    {
        return Action::make('changeAmount')
            ->label('Cambiar monto')
            ->icon(Heroicon::OutlinedCurrencyDollar)
            ->color('gray')
            ->visible(fn (Season $record) => $record->hasFeePlan())
            ->authorize('create', Tariff::class)
            ->modalDescription('Rige para las cuotas desde esa fecha. Las ya emitidas no cambian: si hace falta, anulalas en Cuotas con "Volver a emitirla".')
            ->schema([
                Radio::make('concept')
                    ->label('Monto de')
                    ->options(['fee' => 'La cuota', 'enrollment' => 'La inscripción'])
                    ->default('fee')
                    ->inline()
                    ->required(),
                Select::make('group_id')
                    ->label(Terms::label('group', 'Categoría'))
                    ->placeholder('Todas')
                    ->options(fn () => Group::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                TextInput::make('amount')->label('Monto')->prefix('₲')->numeric()->minValue(1)->required(),
                DatePicker::make('valid_from')->label('Desde')->default(fn () => Filament::getTenant()->today()->toDateString())->required(),
            ])
            ->action(function (Season $record, array $data): void {
                $organization = Filament::getTenant();

                Tariff::query()->create([
                    'fee_concept_id' => ($data['concept'] === 'enrollment' ? FeeConcept::enrollmentFee($organization) : FeeConcept::monthlyFee($organization))->id,
                    'season_id' => $record->id,
                    'group_id' => $data['group_id'] ?? null,
                    'amount' => (int) $data['amount'],
                    'valid_from' => $data['valid_from'],
                    'created_by' => auth()->id(),
                ]);

                Notification::make()->success()->title('Monto guardado.')->send();
            });
    }
}
