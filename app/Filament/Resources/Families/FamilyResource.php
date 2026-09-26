<?php

namespace App\Filament\Resources\Families;

use App\Filament\Resources\Families\Pages\ManageFamilies;
use App\Filament\Support\Terms;
use App\Models\Family;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Familias: agrupan hermanos y tutores. Se arman al importar o desde la ficha del alumno.
 */
class FamilyResource extends Resource
{
    protected static ?string $model = Family::class;

    protected static ?string $slug = 'familias';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|UnitEnum|null $navigationGroup = 'Personas';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'familia';

    protected static ?string $pluralModelLabel = 'familias';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nombre')->placeholder('Familia Benítez')->required()->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['students', 'guardians']))
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('students.first_name')->label(ucfirst(Terms::plural('student', 'Jugador')))->badge(),
                TextColumn::make('guardians.first_name')->label(ucfirst(Terms::plural('guardian', 'Tutor')))->badge()->color('gray'),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageFamilies::route('/')];
    }
}
