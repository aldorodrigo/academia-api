<?php

namespace App\Filament\Resources\Sites;

use App\Filament\Resources\Sites\Pages\ManageSites;
use App\Filament\Support\Terms;
use App\Models\Group;
use App\Models\Site;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Lugares donde entrena el club y sus canchas (salas, aulas). Los permisos son los de las categorías.
 */
class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static ?string $slug = 'lugares';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Académico';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'lugar';

    protected static ?string $pluralModelLabel = 'lugares';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Group::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', Group::class) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('create', Group::class) ?? false;
    }

    /**
     * Un lugar con horarios no se borra (las clases quedarían sin cancha).
     */
    public static function canDelete(Model $record): bool
    {
        return self::canEdit($record) && $record instanceof Site
            && ! $record->venues()->whereHas('schedules')->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nombre')->placeholder('Polideportivo')->required()->maxLength(255),
            TextInput::make('address')->label('Dirección')->maxLength(255),
            Repeater::make('venues')
                ->label(ucfirst(Terms::plural('space', 'Cancha')))
                ->helperText('Si el lugar tiene una sola, dejala con el mismo nombre del lugar.')
                ->relationship()
                ->simple(TextInput::make('name')->required()->maxLength(100)->distinct())
                ->addActionLabel('Agregar '.Terms::singular('space', 'cancha'))
                ->minItems(1)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Lugar')->searchable(),
                TextColumn::make('address')->label('Dirección')->placeholder('—'),
                TextColumn::make('venues.name')->label(ucfirst(Terms::plural('space', 'Cancha')))->badge(),
            ])
            ->recordActions([
                EditAction::make(),
                // Con horarios no se borra (las clases quedarían sin cancha).
                DeleteAction::make()->visible(fn (Site $record) => self::canDelete($record)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSites::route('/')];
    }
}
