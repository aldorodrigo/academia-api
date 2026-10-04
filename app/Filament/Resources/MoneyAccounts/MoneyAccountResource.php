<?php

namespace App\Filament\Resources\MoneyAccounts;

use App\Enums\MoneyAccountType;
use App\Filament\Resources\MoneyAccounts\Pages\ListMoneyAccounts;
use App\Filament\Resources\MoneyAccounts\Pages\ViewMoneyAccount;
use App\Filament\Resources\MoneyAccounts\RelationManagers\EntriesRelationManager;
use App\Models\MoneyAccount;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Cajas, bancos y billeteras. El saldo es la suma de los movimientos (no se edita).
 */
class MoneyAccountResource extends Resource
{
    protected static ?string $model = MoneyAccount::class;

    protected static ?string $slug = 'cuentas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'cuenta';

    protected static ?string $pluralModelLabel = 'cuentas';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nombre')->placeholder('Banco Itaú')->required()->maxLength(255),
            Select::make('type')->label('Tipo')->options(MoneyAccountType::class)->default(MoneyAccountType::Bank)->required()->live(),
            TextInput::make('opening_balance')
                ->label('Saldo inicial')
                ->prefix('₲')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->helperText('Se registra como primer movimiento de la cuenta.')
                ->visibleOn('create'),
            Textarea::make('transfer_details')
                ->label('Datos para transferir')
                ->placeholder("Cuenta corriente 1234567\nTitular: Club Jakare\nRUC 80012345-6")
                ->helperText('Los ven los tutores en la app al informar una transferencia. Vacío = la cuenta no se muestra.')
                ->rows(3)
                ->maxLength(1000)
                ->columnSpanFull()
                ->visible(fn (Get $get) => self::acceptsTransfers($get('type'))),
            Toggle::make('is_active')->label('Activa')->default(true)->visibleOn('edit'),
        ]);
    }

    /**
     * Bancos y billeteras reciben transferencias; la caja no.
     */
    private static function acceptsTransfers(mixed $type): bool
    {
        $type = $type instanceof MoneyAccountType ? $type : MoneyAccountType::tryFrom((string) $type);

        return in_array($type, [MoneyAccountType::Bank, MoneyAccountType::Wallet], true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withSum('entries', 'amount')->with('holder'))
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable(),
                TextColumn::make('type')->label('Tipo')->badge(),
                // Cajas personales: la plata del club que tiene quien cobra en efectivo desde la app.
                TextColumn::make('holder.name')->label('En poder de')->placeholder('Club'),
                TextColumn::make('entries_sum_amount')->label('Saldo')->alignEnd()
                    ->formatStateUsing(fn ($state) => Money::pyg((int) $state)->format())
                    ->default(0),
                IconColumn::make('is_active')->label('Activa')->boolean(),
            ])
            ->recordActions([ViewAction::make()->label('Movimientos'), EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [EntriesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMoneyAccounts::route('/'),
            'view' => ViewMoneyAccount::route('/{record}'),
        ];
    }
}
