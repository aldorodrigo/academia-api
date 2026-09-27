<?php

namespace App\Filament\Resources\DiscountRules;

use App\Enums\DiscountType;
use App\Filament\Resources\DiscountRules\Pages\ManageDiscountRules;
use App\Filament\Support\Terms;
use App\Models\DiscountRule;
use App\Models\FeeConcept;
use App\Models\Season;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Reglas de descuento: hermanos (por posición), convenio y otros. Rigen para los
 * cargos que se generan desde su vigencia; no cambian los ya emitidos.
 */
class DiscountRuleResource extends Resource
{
    protected static ?string $model = DiscountRule::class;

    protected static ?string $slug = 'descuentos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'descuento';

    protected static ?string $pluralModelLabel = 'descuentos';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('type')->label('Tipo')->options(DiscountType::class)->default(DiscountType::Siblings)->required()->live(),
            TextInput::make('name')->label('Nombre')->required()->maxLength(255)
                ->default('Hermanos')
                ->placeholder('Convenio Itaú'),
            Select::make('sibling_position')
                ->label('Para el')
                ->options([2 => '2º hijo', 3 => '3º hijo en adelante'])
                ->visible(fn (Get $get) => self::isSiblings($get))
                ->required(fn (Get $get) => self::isSiblings($get))
                ->helperText('El mayor de los hermanos inscriptos paga completo.'),
            TextInput::make('until_day')
                ->label('Pagando hasta el día')
                ->numeric()->minValue(1)->maxValue(31)
                ->visible(fn (Get $get) => self::isType($get, DiscountType::EarlyPayment))
                ->required(fn (Get $get) => self::isType($get, DiscountType::EarlyPayment))
                ->helperText('Se aplica al registrar un pago que salda la cuota hasta ese día de su mes.'),
            Select::make('students')
                ->label(ucfirst(Terms::plural('student', 'Jugador')))
                ->relationship('students', 'last_name')
                ->getOptionLabelFromRecordUsing(fn ($student) => "{$student->last_name}, {$student->first_name}")
                ->multiple()
                ->searchable(['first_name', 'last_name'])
                ->visible(fn (Get $get) => self::isType($get, DiscountType::Agreement) || self::isType($get, DiscountType::Other)),
            Radio::make('mode')->label('Descuento')
                ->options(['percent' => 'Porcentaje', 'fixed' => 'Monto fijo'])
                ->default('percent')
                ->live()
                ->dehydrated(false)
                ->afterStateHydrated(fn ($component, ?DiscountRule $record) => $component->state($record?->fixed_amount ? 'fixed' : 'percent')),
            TextInput::make('percent')->label('Porcentaje')->suffix('%')->numeric()->minValue(1)->maxValue(100)
                ->visible(fn (Get $get) => $get('mode') !== 'fixed')
                ->required(fn (Get $get) => $get('mode') !== 'fixed')
                ->dehydrateStateUsing(fn ($state, Get $get) => $get('mode') === 'fixed' ? null : $state),
            TextInput::make('fixed_amount')->label('Monto')->prefix('₲')->numeric()->minValue(1)
                ->visible(fn (Get $get) => $get('mode') === 'fixed')
                ->required(fn (Get $get) => $get('mode') === 'fixed')
                ->dehydrateStateUsing(fn ($state, Get $get) => $get('mode') === 'fixed' ? $state : null),
            Select::make('feeConcepts')
                ->label('Sobre')
                ->relationship('feeConcepts', 'name')
                ->multiple()
                ->preload()
                ->default(fn () => array_filter([FeeConcept::monthlyFee(filament()->getTenant())?->id]))
                ->required(),
            // Por defecto desde el inicio de la temporada: así cuenta también para la cuota del mes en curso.
            DatePicker::make('valid_from')->label('Vigente desde')
                ->default(fn () => (Season::currentOrNull()?->starts_on ?? filament()->getTenant()->today()->startOfMonth())->toDateString())
                ->helperText('Rige para las cuotas de ese mes en adelante. Las ya generadas no cambian.')
                ->required(),
            DatePicker::make('valid_to')->label('Hasta')->afterOrEqual('valid_from'),
        ]);
    }

    private static function isSiblings(Get $get): bool
    {
        return self::isType($get, DiscountType::Siblings);
    }

    private static function isType(Get $get, DiscountType $type): bool
    {
        $value = $get('type');

        return ($value instanceof DiscountType ? $value : DiscountType::tryFrom((string) $value)) === $type;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['feeConcepts', 'students']))
            ->columns([
                TextColumn::make('type')->label('Tipo')->badge(),
                TextColumn::make('label')->label('Descuento')->state(fn (DiscountRule $record) => $record->adjustmentLabel()),
                TextColumn::make('feeConcepts.name')->label('Sobre')->badge()->color('gray'),
                TextColumn::make('students_count')->label(ucfirst(Terms::plural('student', 'Jugador')))->counts('students')
                    ->formatStateUsing(fn ($state, DiscountRule $record) => in_array($record->type, [DiscountType::Siblings, DiscountType::EarlyPayment], true) ? '—' : $state),
                TextColumn::make('valid_from')->label('Desde')->date('d/m/Y'),
                TextColumn::make('valid_to')->label('Hasta')->date('d/m/Y')->placeholder('Sin fin'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDiscountRules::route('/')];
    }
}
