<?php

namespace App\Filament\Resources\Venues;

use App\Filament\Resources\Venues\Pages\ManageVenues;
use App\Models\Venue;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class VenueResource extends Resource
{
    protected static ?string $model = Venue::class;

    protected static ?string $slug = 'sedes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Académico';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'sede o cancha';

    protected static ?string $pluralModelLabel = 'sedes y canchas';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nombre')->placeholder('Cancha 1')->required()->maxLength(255),
            TextInput::make('address')->label('Dirección')->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('address')->label('Dirección'),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageVenues::route('/')];
    }
}
