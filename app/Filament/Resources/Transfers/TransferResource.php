<?php

namespace App\Filament\Resources\Transfers;

use App\Actions\Treasury\TransferFunds;
use App\Filament\Resources\Transfers\Pages\ManageTransfers;
use App\Filament\Support\MoneyColumn;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\Transfer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Movimientos entre cuentas (ej. depositar la caja en el banco). No son ingreso ni gasto.
 */
class TransferResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = Transfer::class;

    protected static ?string $slug = 'transferencias';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'transferencia';

    protected static ?string $pluralModelLabel = 'transferencias';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['fromAccount', 'toAccount']))
            ->columns([
                TextColumn::make('transferred_on')->label('Fecha')->date('d/m/Y')->sortable(),
                TextColumn::make('fromAccount.name')->label('Desde'),
                TextColumn::make('toAccount.name')->label('Hacia'),
                TextColumn::make('description')->label('Detalle')->placeholder('—'),
                MoneyColumn::make('amount')->label('Monto'),
                TextColumn::make('voided_at')->label('Estado')->badge()
                    ->state(fn (Transfer $record) => $record->isVoided() ? 'Anulada' : 'Registrada')
                    ->color(fn (Transfer $record) => $record->isVoided() ? 'danger' : 'success')
                    ->tooltip(fn (Transfer $record) => $record->void_reason),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('void')
                    ->label('Anular')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (Transfer $record) => ! $record->isVoided())
                    ->authorize('update')
                    ->schema([Textarea::make('reason')->label('Motivo')->required()])
                    ->action(function (Transfer $record, array $data): void {
                        app(TransferFunds::class)->void($record, $data['reason'], auth()->user());

                        Notification::make()->success()->title('Transferencia anulada.')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTransfers::route('/')];
    }
}
