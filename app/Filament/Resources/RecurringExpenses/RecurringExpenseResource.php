<?php

namespace App\Filament\Resources\RecurringExpenses;

use App\Filament\Resources\RecurringExpenses\Pages\ManageRecurringExpenses;
use App\Filament\Support\MoneyColumn;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\RecurringExpense;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Gastos que se repiten cada mes (alquiler de cancha…): generan un pendiente mensual.
 */
class RecurringExpenseResource extends Resource
{
    // "Gastos recurrentes", no "Gastos Recurrentes".
    use SentenceCaseLabels;

    protected static ?string $model = RecurringExpense::class;

    protected static ?string $slug = 'gastos-recurrentes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 8;

    protected static ?string $modelLabel = 'gasto recurrente';

    protected static ?string $pluralModelLabel = 'gastos recurrentes';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('description')->label('Detalle')->placeholder('Alquiler de cancha')->required()->maxLength(255),
            Select::make('expense_category_id')->label('Categoría')->relationship('category', 'name')->preload()->required()
                ->createOptionForm([TextInput::make('name')->label('Nombre')->required()]),
            Select::make('supplier_id')->label('Proveedor')->relationship('supplier', 'name')->searchable()->preload()
                ->createOptionForm([TextInput::make('name')->label('Nombre')->required(), TextInput::make('phone')->label('Teléfono')]),
            Select::make('money_account_id')->label('Cuenta sugerida')->relationship('moneyAccount', 'name'),
            TextInput::make('amount')->label('Monto')->prefix('₲')->numeric()->minValue(1)->required(),
            TextInput::make('day_of_month')->label('Vence el día')->numeric()->minValue(1)->maxValue(31)->default(5)->required(),
            DatePicker::make('starts_on')->label('Desde')->default(now()->startOfMonth())->required(),
            DatePicker::make('ends_on')->label('Hasta')->afterOrEqual('starts_on'),
            Toggle::make('is_active')->label('Activo')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description')->label('Detalle')->description(fn (RecurringExpense $record) => $record->supplier?->name),
                TextColumn::make('category.name')->label('Categoría')->badge()->color('gray'),
                TextColumn::make('day_of_month')->label('Vence')->formatStateUsing(fn (int $state) => "Día {$state}"),
                MoneyColumn::make('amount')->label('Monto'),
                IconColumn::make('is_active')->label('Activo')->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRecurringExpenses::route('/')];
    }
}
