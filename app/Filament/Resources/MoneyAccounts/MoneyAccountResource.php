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
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
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
            Select::make('type')->label('Tipo')->options(MoneyAccountType::class)->default(MoneyAccountType::Bank)->required(),
            TextInput::make('opening_balance')
                ->label('Saldo inicial')
                ->prefix('₲')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->helperText('Se registra como primer movimiento de la cuenta.')
                ->visibleOn('create'),
            Toggle::make('is_active')->label('Activa')->default(true)->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withSum('entries', 'amount'))
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable(),
                TextColumn::make('type')->label('Tipo')->badge(),
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
