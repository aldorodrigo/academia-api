<?php

namespace App\Filament\Resources\Charges;

use App\Actions\Billing\VoidCharge;
use App\Enums\ChargeStatus;
use App\Filament\Resources\Charges\Pages\ManageCharges;
use App\Filament\Support\ChargeHistory;
use App\Filament\Support\MoneyColumn;
use App\Filament\Support\SentenceCaseLabels;
use App\Filament\Support\Terms;
use App\Filament\Support\WaiveChargeAction;
use App\Models\Charge;
use App\Models\ChargeAdjustment;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Cargos de las cuentas corrientes. Inmutables: se anulan con motivo.
 */
class ChargeResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = Charge::class;

    protected static ?string $slug = 'cargos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'cargo';

    protected static ?string $pluralModelLabel = 'cargos';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student', 'feeConcept', 'group', 'season', 'adjustments', 'organization', 'allocations.payment']))
            ->columns([
                TextColumn::make('student.last_name')->label(Terms::label('student', 'Jugador'))
                    ->formatStateUsing(fn (Charge $record) => "{$record->student->last_name}, {$record->student->first_name}")
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),
                TextColumn::make('description')->label('Concepto')->searchable()
                    ->description(fn (Charge $record) => $record->adjustments->pluck('label')->join(' · ') ?: null),
                TextColumn::make('group.name')->label(Terms::label('group', 'Categoría'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('season.name')->label('Temporada')->toggleable(),
                TextColumn::make('period_start')->label('Corresponde a')->toggleable()
                    ->formatStateUsing(fn (Charge $record) => $record->period_start->format('d/m').' – '.$record->period_end?->format('d/m/Y')),
                TextColumn::make('due_on')->label('Vence')->date('d/m/Y')->sortable(),
                // Las cuotas creadas por adelantado que todavía no empezaron se ven como "Próxima".
                TextColumn::make('status')->label('Estado')->badge()
                    ->state(fn (Charge $record) => $record->status())
                    ->formatStateUsing(fn (Charge $record, ChargeStatus $state) => $record->isUpcoming() ? 'Próxima' : $state->label())
                    ->color(fn (Charge $record, ChargeStatus $state) => $record->isUpcoming() ? 'info' : $state->getColor()),
                MoneyColumn::make('final_amount')->label('Monto'),
                MoneyColumn::make('pending')->label('Pendiente')
                    ->state(fn (Charge $record) => $record->isVoided() ? null : $record->pendingAmount()),
            ])
            ->defaultSort('due_on', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(collect([ChargeStatus::Pending, ChargeStatus::Overdue, ChargeStatus::Paid, ChargeStatus::Voided])
                        ->mapWithKeys(fn (ChargeStatus $s) => [$s->value => $s->label()]))
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'anulado' => $query->whereNotNull('voided_at'),
                        'pagado' => $query->whereIn('id', self::paidIds()),
                        'vencido' => $query->whereNull('voided_at')->whereIn('id', self::overdueIds())->whereNotIn('id', self::paidIds()),
                        'pendiente' => $query->whereNull('voided_at')->whereNotIn('id', self::overdueIds())->whereNotIn('id', self::paidIds()),
                        default => $query,
                    }),
                SelectFilter::make('season_id')->label('Temporada')->relationship('season', 'name')->multiple(),
                SelectFilter::make('fee_concept_id')->label('Concepto')->relationship('feeConcept', 'name'),
                SelectFilter::make('group_id')->label(Terms::label('group', 'Categoría'))->relationship('group', 'name')->preload(),
                SelectFilter::make('student_id')->label(Terms::label('student', 'Jugador'))
                    ->relationship('student', 'last_name')
                    ->getOptionLabelFromRecordUsing(fn ($student) => "{$student->last_name}, {$student->first_name}")
                    ->searchable(),
                Filter::make('period')
                    ->schema([DatePicker::make('period')->label('Mes')->format('Y-m-01')->displayFormat('m/Y')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['period'] ?? null, fn (Builder $query, string $period) => $query->whereDate('period', $period))),
            ])
            ->recordActions([self::detailAction(), self::historyAction(), WaiveChargeAction::make(), WaiveChargeAction::undo(), self::voidAction()])
            ->toolbarActions([WaiveChargeAction::bulk()]);
    }

    /**
     * Vencidos: pasó el vencimiento más los días de gracia (fecha local).
     *
     * @return list<int>
     */
    private static function overdueIds(): array
    {
        $organization = filament()->getTenant();
        $limit = $organization->today()->subDays((int) $organization->billing('grace_days'))->toDateString();

        return Charge::query()->whereNull('voided_at')->whereDate('due_on', '<', $limit)->pluck('id')->all();
    }

    /**
     * Pagados: no anulados y sin nada pendiente.
     *
     * @return list<int>
     */
    private static function paidIds(): array
    {
        return Charge::query()->whereNull('voided_at')->with('allocations.payment')->get()
            ->filter(fn (Charge $charge) => $charge->pendingAmount() === 0)
            ->modelKeys();
    }

    private static function detailAction(): Action
    {
        return Action::make('detail')
            ->label('Detalle')
            ->icon(Heroicon::OutlinedEye)
            ->modalHeading(fn (Charge $record) => $record->description)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            ->modalContent(fn (Charge $record) => new HtmlString(view('filament.charges.detail', [
                'charge' => $record,
                'lines' => [
                    [$record->feeConcept->name, Money::pyg($record->base_amount)->format()],
                    ...$record->adjustments->map(fn (ChargeAdjustment $a) => [$a->label, Money::pyg($a->amount)->format()])->all(),
                    ...$record->activeAllocations()->map(fn ($a) => [
                        "Pagado (recibo N° {$a->payment->receiptLabel()})".($a->early_payment_discount ? " · {$a->early_payment_label} −".Money::pyg($a->early_payment_discount)->format() : ''),
                        Money::pyg(-$a->covered())->format(),
                    ])->all(),
                ],
                'total' => Money::pyg($record->pendingAmount())->format(),
            ])->render()));
    }

    private static function historyAction(): Action
    {
        return Action::make('history')
            ->label('Historial')
            ->icon(Heroicon::OutlinedClock)
            ->modalHeading(fn (Charge $record) => "Historial: {$record->description}")
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            ->modalContent(fn (Charge $record) => new HtmlString(view('filament.charges.history', [
                'entries' => ChargeHistory::for($record),
                'timezone' => $record->organization->timezone,
            ])->render()));
    }

    private static function voidAction(): Action
    {
        return Action::make('void')
            ->label('Anular')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Charge $record) => ! $record->isVoided())
            ->authorize('update')
            ->schema([
                Textarea::make('reason')->label('Motivo')->required(),
                Toggle::make('reissue')
                    ->label('Volver a emitirla')
                    ->helperText('Se crea de nuevo con los montos, descuentos y becas de hoy (por ejemplo, después de aprobar una beca).')
                    ->visible(fn (Charge $record) => VoidCharge::canReissue($record)),
            ])
            ->modalDescription('El cargo no se borra: queda anulado con el motivo y no suma al saldo.')
            ->action(function (Charge $record, array $data): void {
                app(VoidCharge::class)->handle($record, $data['reason'], auth()->user(), (bool) ($data['reissue'] ?? false));

                Notification::make()->success()->title(($data['reissue'] ?? false) ? 'Cuota anulada y emitida de nuevo.' : 'Cargo anulado.')->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => ManageCharges::route('/')];
    }
}
