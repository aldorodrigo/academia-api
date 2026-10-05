<?php

namespace App\Filament\Resources\Payments;

use App\Actions\Billing\VoidPayment;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Support\MoneyColumn;
use App\Filament\Support\SentenceCaseLabels;
use App\Http\Controllers\ReceiptController;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Pagos de las familias con su recibo. No se editan: se anulan con motivo.
 */
class PaymentResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = Payment::class;

    protected static ?string $slug = 'pagos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 0;

    protected static ?string $modelLabel = 'pago';

    protected static ?string $pluralModelLabel = 'pagos';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['family.students', 'moneyAccount', 'creator', 'allocations.charge.student']))
            ->columns([
                TextColumn::make('receipt_number')->label('Recibo')
                    ->formatStateUsing(fn (Payment $record) => $record->receiptLabel())
                    ->sortable(),
                TextColumn::make('received_on')->label('Fecha')->date('d/m/Y')->sortable(),
                TextColumn::make('family.name')->label('Familia')
                    ->description(fn (Payment $record) => $record->family->students->pluck('first_name')->join(', '))
                    ->searchable(),
                TextColumn::make('allocations')->label('Aplicado a')
                    ->state(fn (Payment $record) => $record->allocations
                        ->map(fn (PaymentAllocation $a) => "{$a->charge->student->first_name} · {$a->charge->description}".($a->from_credit ? ' (con saldo a favor)' : ''))
                        ->push(...($record->credit() > 0 ? ['Saldo a favor '.Money::pyg($record->credit())->format()] : []))
                        ->all())
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->expandableLimitedList(),
                TextColumn::make('method')->label('Método')->badge()->color('gray'),
                TextColumn::make('moneyAccount.name')->label('Cuenta')->toggleable(isToggledHiddenByDefault: true),
                // Con varias personas cobrando a la Caja, se sabe quién cobró qué.
                TextColumn::make('creator.name')->label('Cobró')->toggleable(),
                MoneyColumn::make('amount')->label('Monto'),
                TextColumn::make('voided_at')->label('Estado')->badge()
                    ->state(fn (Payment $record) => $record->isVoided() ? 'Anulado' : 'Registrado')
                    ->color(fn (Payment $record) => $record->isVoided() ? 'danger' : 'success')
                    ->tooltip(fn (Payment $record) => $record->void_reason),
            ])
            ->defaultSort('receipt_number', 'desc')
            ->filters([
                SelectFilter::make('method')->label('Método')->options(PaymentMethod::class),
                SelectFilter::make('money_account_id')->label('Cuenta')->relationship('moneyAccount', 'name'),
                TernaryFilter::make('voided')->label('Anulados')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('voided_at'),
                        false: fn (Builder $query) => $query->whereNull('voided_at'),
                    ),
            ])
            ->recordActions([
                Action::make('receipt')
                    ->label('Recibo')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->url(fn (Payment $record) => ReceiptController::signedUrl($record))
                    ->openUrlInNewTab(),
                Action::make('void')
                    ->label('Anular')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (Payment $record) => ! $record->isVoided())
                    ->authorize('update')
                    ->modalDescription('Los cargos que cubría vuelven a quedar pendientes y se registra el contra-movimiento en la cuenta. El recibo queda marcado como anulado.')
                    ->schema([Textarea::make('reason')->label('Motivo')->required()])
                    ->action(function (Payment $record, array $data): void {
                        app(VoidPayment::class)->handle($record, $data['reason'], auth()->user());

                        Notification::make()->success()->title("Recibo N° {$record->receiptLabel()} anulado.")->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePayments::route('/')];
    }
}
