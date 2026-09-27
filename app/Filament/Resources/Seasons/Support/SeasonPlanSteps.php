<?php

namespace App\Filament\Resources\Seasons\Support;

use App\Enums\DailyBasis;
use App\Enums\DailyGrouping;
use App\Enums\FeeFrequency;
use App\Enums\MidPeriod;
use App\Enums\SeasonKind;
use App\Filament\Support\Terms;
use App\Models\Charge;
use App\Models\Group;
use App\Models\Program;
use App\Models\Season;
use App\Models\Tariff;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Support\HtmlString;

/**
 * Pasos del asistente de temporada. Una pregunta por bloque, en el idioma del club, con
 * valores por defecto y el efecto de cada opción en fechas y montos. Los pasos de cobro
 * (2 y 3) los reutiliza "Configurar cobro".
 */
class SeasonPlanSteps
{
    /**
     * Crear cuotas y montos: permiso de tarifas.
     */
    public static function canPlan(): bool
    {
        return auth()->user()?->can('create', Tariff::class) ?? false;
    }

    /**
     * "Crear todas las cuotas al inscribir": permiso de cuotas.
     */
    public static function canIssueUpfront(): bool
    {
        return auth()->user()?->can('create', Charge::class) ?? false;
    }

    /**
     * @return list<Step>
     */
    public static function wizard(): array
    {
        return [
            Step::make('Temporada')->icon('heroicon-o-calendar-days')->schema(self::seasonFields()),
            ...(self::canPlan() ? self::planSteps() : []),
            Step::make('Revisar')->icon('heroicon-o-check-circle')->schema(self::review()),
        ];
    }

    /**
     * Pasos 2 y 3 (también en "Configurar cobro").
     *
     * @return list<Step>
     */
    public static function planSteps(): array
    {
        return [
            Step::make('Cuotas')->icon('heroicon-o-banknotes')->schema(self::feeFields()),
            Step::make('Cuándo se crean')->icon('heroicon-o-clock')->schema(self::issueFields()),
        ];
    }

    /**
     * @return list<mixed>
     */
    public static function seasonFields(): array
    {
        return [
            Radio::make('copy_from')
                ->label('¿Empezás de cero o copiás una temporada?')
                ->options(fn () => ['' => 'Empezar de cero'] + Season::query()->orderByDesc('starts_on')->limit(3)->get()
                    ->mapWithKeys(fn (Season $season) => [(string) $season->id => "Copiar {$season->name}"])->all())
                ->descriptions(fn () => ['' => 'Cargás todo desde el principio.'] + Season::query()->orderByDesc('starts_on')->limit(3)->get()
                    ->mapWithKeys(fn (Season $season) => [(string) $season->id => 'Misma duración, disciplinas, cuotas y montos. Podés aplicar un aumento.'])->all())
                ->visible(fn () => Season::query()->exists())
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set) {
                    $values = filled($state) && ($source = Season::query()->find($state))
                        ? SeasonPlan::copyOf($source)
                        : SeasonPlan::defaults(filament()->getTenant());

                    foreach ($values as $key => $value) {
                        if ($key !== 'copy_from') {
                            $set($key, $value);
                        }
                    }
                }),
            Text::make('Se completaron todos los pasos con la temporada copiada. Revisá los montos: podés ir directo a Revisar.')
                ->color('success')
                ->visible(fn (Get $get) => filled($get('copy_from'))),
            CheckboxList::make('program_ids')
                ->label(ucfirst(Terms::plural('program', 'Disciplina')))
                ->options(fn () => Program::query()->orderBy('name')->pluck('name', 'id'))
                ->columns(3)
                ->visible(fn () => Program::query()->count() > 1)
                ->required(fn () => Program::query()->count() > 1)
                ->validationMessages(['required' => 'Elegí al menos una disciplina.'])
                ->live(),
            Radio::make('kind')
                ->label('¿Cuánto dura?')
                ->options(SeasonKind::class)
                ->descriptions(fn (Get $get) => collect(SeasonKind::cases())->mapWithKeys(fn (SeasonKind $kind) => [
                    $kind->value => $kind->example(self::startsOn($get)),
                ])->all())
                ->inline()
                ->required()
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculateDates($get, $set, planToo: true)),
            Grid::make(2)->schema([
                DatePicker::make('starts_on')
                    ->label('Empieza')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculateDates($get, $set)),
                DatePicker::make('ends_on')
                    ->label('Termina')
                    ->helperText('Se calcula según la duración; podés cambiarla.')
                    ->required()
                    ->afterOrEqual('starts_on')
                    ->live(onBlur: true)
                    ->validationMessages(['after_or_equal' => 'Tiene que terminar después de empezar.']),
            ]),
            TextInput::make('name')
                ->label('Nombre')
                ->helperText('Sugerido según la duración; podés cambiarlo.')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true),
        ];
    }

    /**
     * @return list<mixed>
     */
    public static function feeFields(): array
    {
        return [
            Radio::make('fee_frequency')
                ->label('¿Cada cuánto se cobra?')
                ->options(FeeFrequency::class)
                ->descriptions([
                    FeeFrequency::Monthly->value => 'Una cuota por mes.',
                    FeeFrequency::Fortnightly->value => 'Del 1 al 15 y del 16 a fin de mes.',
                    FeeFrequency::Weekly->value => 'De lunes a domingo.',
                    FeeFrequency::Daily->value => 'Un monto por día de entrenamiento o por clase.',
                ])
                ->inline()
                ->required()
                ->disabled(fn (?Season $record) => self::hasCharges($record))
                ->helperText(fn (?Season $record) => self::hasCharges($record) ? 'Ya hay cuotas emitidas: no se puede cambiar.' : null)
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => $set('due_days', FeeFrequency::from(self::value($get, 'fee_frequency'))->defaultDueDays((int) filament()->getTenant()->billing('due_day')))),
            Grid::make(2)
                ->visible(fn (Get $get) => self::value($get, 'fee_frequency') === FeeFrequency::Daily->value)
                ->schema([
                    Radio::make('daily_basis')
                        ->label('¿Qué días se cuentan?')
                        ->options(DailyBasis::class)
                        ->descriptions([
                            DailyBasis::Training->value => 'Los días con horario de la categoría.',
                            DailyBasis::Attendance->value => 'Próximamente: requiere el módulo de Asistencia.',
                        ])
                        ->disabled(fn (?Season $record) => self::hasCharges($record))
                        ->required(fn (Get $get) => self::value($get, 'fee_frequency') === FeeFrequency::Daily->value)
                        ->live(),
                    Radio::make('daily_grouping')
                        ->label('¿Cómo se agrupa?')
                        ->options(DailyGrouping::class)
                        ->descriptions([DailyGrouping::Month->value => 'Recomendado: una sola cuota y un solo pago por mes.'])
                        ->required(fn (Get $get) => self::value($get, 'fee_frequency') === FeeFrequency::Daily->value)
                        ->live(),
                ]),
            Grid::make(3)->schema([
                TextInput::make('fee_amount')
                    ->label(fn (Get $get) => (FeeFrequency::tryFrom(self::value($get, 'fee_frequency')) ?? FeeFrequency::Monthly)->amountLabel())
                    ->prefix('₲')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->live(onBlur: true)
                    ->helperText(fn (Get $get) => filled($get('base_fee_amount')) ? 'Antes: '.Money::pyg((int) $get('base_fee_amount'))->format() : null)
                    ->hiddenOn('edit'),
                TextInput::make('increase_percent')
                    ->label('Aumento')
                    ->suffix('%')
                    ->numeric()
                    ->visible(fn (Get $get) => filled($get('copy_from')))
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                        $set('fee_amount', SeasonPlan::increase($get('base_fee_amount'), $state));
                        $set('enrollment_fee_amount', SeasonPlan::increase($get('base_enrollment_fee_amount'), $state));
                        $set('group_amounts', collect($get('group_amounts') ?? [])->map(fn (array $row) => [
                            ...$row,
                            'amount' => SeasonPlan::increase(isset($row['base_amount']) ? (int) $row['base_amount'] : null, $state) ?? $row['amount'] ?? null,
                        ])->all());
                    }),
                TextInput::make('enrollment_fee_amount')
                    ->label('Inscripción (opcional)')
                    ->prefix('₲')
                    ->numeric()
                    ->minValue(1)
                    ->live(onBlur: true)
                    ->hiddenOn('edit'),
            ]),
            Hidden::make('base_fee_amount'),
            Hidden::make('base_enrollment_fee_amount'),
            Toggle::make('has_group_amounts')
                ->label('¿Alguna '.Terms::singular('group', 'categoría').' paga distinto?')
                ->live()
                ->hiddenOn('edit'),
            Repeater::make('group_amounts')
                ->hiddenLabel()
                ->visible(fn (Get $get, string $operation) => $operation !== 'edit' && $get('has_group_amounts'))
                ->table([
                    Repeater\TableColumn::make(Terms::label('group', 'Categoría')),
                    Repeater\TableColumn::make('Monto'),
                ])
                ->schema([
                    Select::make('group_id')
                        ->options(fn (Get $get) => Group::query()->where('is_active', true)
                            ->when(filled($get('../../program_ids')), fn ($query) => $query->whereIn('program_id', $get('../../program_ids')))
                            ->orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->distinct(),
                    TextInput::make('amount')->prefix('₲')->numeric()->minValue(1)->required(),
                    Hidden::make('base_amount'),
                ])
                ->addActionLabel('Agregar '.Terms::singular('group', 'categoría'))
                ->defaultItems(1),
            TextInput::make('due_days')
                ->label('Vence a los … días de empezar el período')
                ->numeric()
                ->minValue(0)
                ->maxValue(60)
                ->required()
                ->suffix('días')
                ->live(onBlur: true)
                ->helperText(fn (Get $get) => SeasonPlan::dueExample(self::state($get))),
        ];
    }

    /**
     * @return list<mixed>
     */
    public static function issueFields(): array
    {
        return [
            Radio::make('issue_upfront')
                ->label('¿Cuándo se crean las cuotas de cada jugador?')
                ->options(fn () => [
                    '0' => 'Al empezar cada período (recomendado)',
                    ...(self::canIssueUpfront() ? ['1' => 'Todas juntas al inscribir'] : []),
                ])
                ->descriptions(fn (Get $get) => [
                    '0' => 'El padre ve solo la cuota del período en curso.',
                    '1' => 'El padre ve las '.SeasonPlan::periodsCount(self::state($get)).' cuotas: la del período en curso en "A pagar" y el resto en "Próximas". Si se da de baja, las futuras sin pagar se anulan solas.',
                ])
                ->formatStateUsing(fn ($state) => filter_var($state, FILTER_VALIDATE_BOOLEAN) ? '1' : '0')
                ->required(),
            Section::make('Opciones avanzadas')
                ->collapsed()
                ->compact()
                ->schema([
                    Radio::make('mid_period')
                        ->label('Si alguien se inscribe a mitad de período, del período en curso se cobra')
                        ->options(MidPeriod::class)
                        ->helperText('Es el valor por defecto: se puede cambiar en cada inscripción.')
                        ->required(),
                ]),
        ];
    }

    /**
     * @return list<mixed>
     */
    public static function review(): array
    {
        return [
            Text::make(fn (Get $get) => SeasonPlan::summary(self::state($get), self::canPlan())),
            Text::make(fn (Get $get) => self::examplesTable(self::state($get)))
                ->visible(fn (Get $get) => self::canPlan() && filled(self::value($get, 'fee_frequency'))),
            Text::make('Tocá un paso de arriba para corregirlo.')->color('gray'),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function examplesTable(array $state): HtmlString
    {
        $rows = SeasonPlan::examples($state)
            ->map(fn (array $row) => '<tr><td style="padding:4px 12px 4px 0">'.e(ucfirst($row['period'])).'</td>'
                .'<td style="padding:4px 12px 4px 0">'.e($row['due_on']).'</td>'
                .'<td style="padding:4px 0;text-align:right">'.e($row['amount']).'</td></tr>')
            ->join('');

        return new HtmlString('<p style="font-weight:600;margin-bottom:4px">Primeras cuotas de cada jugador</p>'
            .'<table style="font-size:0.875rem"><thead><tr><th style="text-align:left;padding-right:12px">Período</th>'
            .'<th style="text-align:left;padding-right:12px">Vence</th><th style="text-align:right">Monto</th></tr></thead>'
            ."<tbody>{$rows}</tbody></table>");
    }

    /**
     * Estado del asistente para los cálculos (desde cualquier paso).
     *
     * @return array<string, mixed>
     */
    public static function state(Get $get): array
    {
        return collect([
            'name', 'kind', 'starts_on', 'ends_on', 'program_ids', 'fee_frequency', 'daily_basis', 'daily_grouping',
            'due_days', 'issue_upfront', 'mid_period', 'fee_amount', 'enrollment_fee_amount', 'has_group_amounts', 'group_amounts',
        ])->mapWithKeys(fn (string $key) => [$key => self::value($get, $key)])->all();
    }

    /**
     * Valor del campo (los radios con enum devuelven el enum).
     */
    public static function value(Get $get, string $key): mixed
    {
        $value = $get($key);

        return $value instanceof BackedEnum ? $value->value : $value;
    }

    private static function startsOn(Get $get): CarbonImmutable
    {
        return filled($get('starts_on')) ? CarbonImmutable::parse($get('starts_on')) : filament()->getTenant()->today();
    }

    /**
     * Al cambiar la duración o el inicio: fin y nombre sugerido (y el plan, al cambiar la duración).
     */
    private static function recalculateDates(Get $get, Set $set, bool $planToo = false): void
    {
        $kind = SeasonKind::tryFrom((string) self::value($get, 'kind'));

        if ($kind === null || blank($get('starts_on'))) {
            return;
        }

        $dates = SeasonPlan::dates($kind, self::startsOn($get));
        $set('ends_on', $dates['ends_on']);
        $set('name', $dates['name']);

        if ($planToo && blank($get('copy_from'))) {
            $frequency = $kind->defaultFrequency();
            $set('fee_frequency', $frequency->value);
            $set('due_days', $frequency->defaultDueDays((int) filament()->getTenant()->billing('due_day')));
        }
    }

    private static function hasCharges(?Season $season): bool
    {
        return $season?->exists && $season->charges()->exists();
    }
}
