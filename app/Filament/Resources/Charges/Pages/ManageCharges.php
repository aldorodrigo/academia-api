<?php

namespace App\Filament\Resources\Charges\Pages;

use App\Actions\Billing\CreateManualCharges;
use App\Actions\Billing\DueDate;
use App\Actions\Billing\GenerateMonthlyCharges;
use App\Enums\FeeConceptKind;
use App\Filament\Resources\Charges\ChargeResource;
use App\Filament\Support\Terms;
use App\Models\Charge;
use App\Models\FeeConcept;
use App\Models\Group;
use App\Models\Season;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ManageCharges extends ManageRecords
{
    protected static string $resource = ChargeResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->generateAction(), $this->newChargeAction()];
    }

    /**
     * Cuotas del mes, con vista previa antes de confirmar. Idempotente.
     */
    private function generateAction(): Action
    {
        return Action::make('generate')
            ->label('Generar cuotas')
            ->icon(Heroicon::OutlinedArrowPath)
            ->authorize('create', Charge::class)
            ->modalHeading('Generar cuotas del mes')
            ->modalSubmitActionLabel('Generar')
            ->schema([
                Select::make('period')
                    ->label('Mes')
                    ->options(fn () => $this->seasonMonths())
                    ->default(fn () => Filament::getTenant()->today()->startOfMonth()->toDateString())
                    ->required()
                    ->live(),
                Text::make(fn (Get $get) => $this->preview($get('period'))),
            ])
            ->action(function (array $data): void {
                try {
                    $summary = app(GenerateMonthlyCharges::class)->handle(
                        Filament::getTenant(),
                        CarbonImmutable::parse($data['period']),
                        createdBy: auth()->id(),
                    );
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title("Cuotas generadas: {$summary['created']}.")
                    ->body($this->summaryText($summary))
                    ->send();
            });
    }

    private function newChargeAction(): Action
    {
        return Action::make('newCharge')
            ->label('Nuevo cargo')
            ->icon(Heroicon::OutlinedPlus)
            ->authorize('create', Charge::class)
            ->modalHeading('Nuevo cargo')
            ->schema([
                Select::make('fee_concept_id')
                    ->label('Concepto')
                    ->options(fn () => FeeConcept::query()->where('kind', FeeConceptKind::OneTime)->orderBy('name')->pluck('name', 'id'))
                    ->createOptionForm([TextInput::make('name')->label('Nombre')->placeholder('Torneo de primavera')->required()])
                    ->createOptionUsing(fn (array $data) => FeeConcept::query()->create(['name' => $data['name'], 'kind' => FeeConceptKind::OneTime])->id)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (?string $state, Set $set) => $set('description', FeeConcept::query()->find($state)?->name)),
                TextInput::make('description')->label('Descripción')->required()->maxLength(255),
                TextInput::make('amount')->label('Monto')->prefix('₲')->numeric()->minValue(1)->required(),
                DatePicker::make('due_on')->label('Vence')
                    ->default(fn () => DueDate::next(Filament::getTenant(), Filament::getTenant()->today())->toDateString())
                    ->required(),
                Radio::make('target')->label('A quién')
                    ->options(['group' => 'Toda una '.Terms::singular('group', 'Categoría'), 'students' => ucfirst(Terms::plural('student', 'Jugador')).' puntuales'])
                    ->default('group')
                    ->live(),
                Select::make('group_id')->label(Terms::label('group', 'Categoría'))
                    ->options(fn () => Group::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->visible(fn (Get $get) => $get('target') === 'group')
                    ->required(fn (Get $get) => $get('target') === 'group'),
                Select::make('student_ids')->label(ucfirst(Terms::plural('student', 'Jugador')))
                    ->multiple()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Student::query()
                        ->where(fn ($query) => $query->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))
                        ->limit(20)->get()->mapWithKeys(fn (Student $s) => [$s->id => "{$s->last_name}, {$s->first_name}"]))
                    ->getOptionLabelsUsing(fn (array $values) => Student::query()->whereKey($values)->get()
                        ->mapWithKeys(fn (Student $s) => [$s->id => "{$s->last_name}, {$s->first_name}"]))
                    ->visible(fn (Get $get) => $get('target') === 'students')
                    ->required(fn (Get $get) => $get('target') === 'students'),
            ])
            ->action(function (array $data): void {
                $count = app(CreateManualCharges::class)->handle(
                    Filament::getTenant(),
                    FeeConcept::query()->findOrFail($data['fee_concept_id']),
                    (int) $data['amount'],
                    $data['description'],
                    CarbonImmutable::parse($data['due_on']),
                    studentIds: $data['target'] === 'students' ? array_map('intval', $data['student_ids'] ?? []) : [],
                    group: $data['target'] === 'group' ? Group::query()->find($data['group_id']) : null,
                    createdBy: auth()->user(),
                );

                Notification::make()->success()->title("Cargos creados: {$count}.")->send();
            });
    }

    /**
     * @return array<string, string>
     */
    private function seasonMonths(): array
    {
        $season = Season::currentOrNull();

        if ($season === null) {
            return [];
        }

        $months = [];
        for ($month = CarbonImmutable::parse($season->starts_on)->startOfMonth(); $month->lte($season->ends_on); $month = $month->addMonth()) {
            $months[$month->toDateString()] = ucfirst($month->locale('es')->translatedFormat('F Y'));
        }

        return $months;
    }

    private function preview(?string $period): string
    {
        if (blank($period)) {
            return 'Elegí el mes.';
        }

        $summary = app(GenerateMonthlyCharges::class)->handle(Filament::getTenant(), CarbonImmutable::parse($period), dryRun: true);

        if ($summary['out_of_season']) {
            return 'Ese mes no está dentro de la temporada actual.';
        }

        return "Se van a crear {$summary['created']} cuotas. ".$this->summaryText($summary);
    }

    /**
     * @param  array{existing: int, full_scholarship: int, without_tariff: list<string>}  $summary
     */
    private function summaryText(array $summary): string
    {
        return collect([
            $summary['existing'] ? "{$summary['existing']} ya estaban generadas." : null,
            $summary['full_scholarship'] ? "{$summary['full_scholarship']} con beca total (no se cobran)." : null,
            $summary['without_tariff'] ? 'Sin tarifa: '.implode(', ', $summary['without_tariff']).'.' : null,
        ])->filter()->join(' ');
    }
}
