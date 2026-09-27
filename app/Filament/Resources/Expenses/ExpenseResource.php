<?php

namespace App\Filament\Resources\Expenses;

use App\Actions\Treasury\ExpenseLedger;
use App\Enums\ExpenseStatus;
use App\Filament\Resources\Expenses\Pages\ManageExpenses;
use App\Filament\Support\MoneyColumn;
use App\Models\Expense;
use App\Models\MoneyAccount;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/**
 * Gastos: pagados (salen de una cuenta) o pendientes (generados por un recurrente).
 * No se editan: se anulan con motivo.
 */
class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static ?string $slug = 'gastos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingDown;

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 7;

    protected static ?string $modelLabel = 'gasto';

    protected static ?string $pluralModelLabel = 'gastos';

    public static function getNavigationBadge(): ?string
    {
        $pending = Expense::query()->where('status', ExpenseStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'supplier', 'moneyAccount']))
            ->columns([
                TextColumn::make('description')->label('Detalle')->searchable()
                    ->description(fn (Expense $record) => $record->supplier?->name),
                TextColumn::make('category.name')->label('Categoría')->badge()->color('gray'),
                TextColumn::make('date')->label('Fecha')->date('d/m/Y')
                    ->state(fn (Expense $record) => $record->paid_on ?? $record->due_on)
                    ->description(fn (Expense $record) => $record->status === ExpenseStatus::Pending ? 'vence' : null),
                TextColumn::make('moneyAccount.name')->label('Cuenta')->placeholder('—'),
                TextColumn::make('status')->label('Estado')->badge()->tooltip(fn (Expense $record) => $record->void_reason),
                MoneyColumn::make('amount')->label('Monto'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(ExpenseStatus::class),
                SelectFilter::make('expense_category_id')->label('Categoría')->relationship('category', 'name'),
                SelectFilter::make('supplier_id')->label('Proveedor')->relationship('supplier', 'name')->searchable(),
            ])
            ->recordActions([
                Action::make('pay')
                    ->label('Pagar')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->color('success')
                    ->visible(fn (Expense $record) => $record->status === ExpenseStatus::Pending)
                    ->authorize('update')
                    ->fillForm(fn (Expense $record) => [
                        'money_account_id' => $record->money_account_id ?? MoneyAccount::query()->value('id'),
                        'paid_on' => Filament::getTenant()->today()->toDateString(),
                    ])
                    ->schema([
                        Select::make('money_account_id')->label('Sale de')
                            ->options(fn () => MoneyAccount::query()->where('is_active', true)->pluck('name', 'id'))->required(),
                        DatePicker::make('paid_on')->label('Fecha de pago')->required(),
                    ])
                    ->action(function (Expense $record, array $data): void {
                        app(ExpenseLedger::class)->pay($record, MoneyAccount::query()->findOrFail($data['money_account_id']), CarbonImmutable::parse($data['paid_on']), auth()->user());

                        Notification::make()->success()->title('Gasto pagado.')->send();
                    }),
                Action::make('attachment')
                    ->label('Comprobante')
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->visible(fn (Expense $record) => filled($record->attachment))
                    ->action(fn (Expense $record) => Storage::disk('local')->download($record->attachment)),
                Action::make('void')
                    ->label('Anular')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (Expense $record) => $record->status !== ExpenseStatus::Voided)
                    ->authorize('update')
                    ->modalDescription('Si estaba pagado, la plata vuelve a la cuenta con un contra-movimiento.')
                    ->schema([Textarea::make('reason')->label('Motivo')->required()])
                    ->action(function (Expense $record, array $data): void {
                        app(ExpenseLedger::class)->void($record, $data['reason'], auth()->user());

                        Notification::make()->success()->title('Gasto anulado.')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageExpenses::route('/')];
    }
}
