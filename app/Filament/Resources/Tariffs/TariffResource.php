<?php

namespace App\Filament\Resources\Tariffs;

use App\Enums\FeeConceptKind;
use App\Filament\Resources\Tariffs\Pages\ManageTariffs;
use App\Filament\Support\MoneyColumn;
use App\Filament\Support\SentenceCaseLabels;
use App\Filament\Support\Terms;
use App\Models\Season;
use App\Models\Tariff;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Tarifas: no se editan. Para cambiar un monto se carga una tarifa nueva con otra
 * vigencia; los cargos ya emitidos no cambian.
 */
class TariffResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = Tariff::class;

    protected static ?string $slug = 'tarifas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'tarifa';

    protected static ?string $pluralModelLabel = 'tarifas';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('fee_concept_id')
                ->label('Concepto')
                ->relationship('feeConcept', 'name')
                ->createOptionForm([
                    TextInput::make('name')->label('Nombre')->required(),
                    Select::make('kind')->label('Se cobra')->options(FeeConceptKind::class)->default(FeeConceptKind::OneTime)->required(),
                ])
                ->preload()
                ->required(),
            Select::make('season_id')
                ->label('Temporada')
                ->relationship('season', 'name')
                ->default(fn () => Season::defaultFor()?->id)
                ->live()
                ->afterStateUpdated(fn ($state, $set) => $set('valid_from', Season::query()->find($state)?->starts_on?->toDateString()))
                ->required(),
            Select::make('group_id')
                ->label(Terms::label('group', 'Categoría'))
                ->relationship('group', 'name')
                ->placeholder('Todas')
                ->helperText(fn () => 'Vacío: vale para '.Terms::gendered('group', 'Categoría', 'todos', 'todas').'. Una tarifa '
                    .Terms::of('group', 'Categoría').' gana sobre la general.'),
            TextInput::make('amount')->label('Monto')->prefix('₲')->numeric()->minValue(1)->required(),
            DatePicker::make('valid_from')
                ->label('Vigente desde')
                ->default(fn () => Season::defaultFor()?->starts_on?->toDateString())
                ->helperText('Rige para los cargos desde esa fecha. Los ya emitidos no cambian.')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['feeConcept', 'season', 'group']))
            ->columns([
                TextColumn::make('feeConcept.name')->label('Concepto')->sortable(),
                TextColumn::make('season.name')->label('Temporada'),
                TextColumn::make('group.name')->label(Terms::label('group', 'Categoría'))->placeholder(fn () => Terms::gendered('group', 'Categoría', 'Todos', 'Todas')),
                MoneyColumn::make('amount')->label('Monto'),
                TextColumn::make('valid_from')->label('Desde')->date('d/m/Y')->sortable(),
            ])
            ->defaultSort('valid_from', 'desc')
            ->filters([
                SelectFilter::make('season_id')->label('Temporada')->relationship('season', 'name')->multiple()
                    ->default(fn () => Season::query()->open()->pluck('id')->all()),
                SelectFilter::make('fee_concept_id')->label('Concepto')->relationship('feeConcept', 'name'),
            ])
            ->recordActions([DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTariffs::route('/')];
    }
}
