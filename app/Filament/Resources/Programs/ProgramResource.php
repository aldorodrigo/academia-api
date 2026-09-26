<?php

namespace App\Filament\Resources\Programs;

use App\Enums\GroupCriterion;
use App\Filament\Resources\Programs\Pages\ManagePrograms;
use App\Filament\Support\Terms;
use App\Models\Program;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ProgramResource extends Resource
{
    protected static ?string $model = Program::class;

    protected static ?string $slug = 'programas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|UnitEnum|null $navigationGroup = 'Académico';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return Terms::singular('program', 'Disciplina');
    }

    public static function getPluralModelLabel(): string
    {
        return Terms::plural('program', 'Disciplina');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nombre')->placeholder('Fútbol')->required()->maxLength(255),
            Select::make('group_criterion')
                ->label('Criterio de '.Terms::plural('group', 'Categoría'))
                ->options(GroupCriterion::class)
                ->default(GroupCriterion::BirthYear)
                ->required()
                ->helperText('Por año de nacimiento (Sub-10, Sub-12…) o por nivel (Inicial, Avanzado…).'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('group_criterion')->label('Criterio')->badge(),
                TextColumn::make('groups_count')->label(ucfirst(Terms::plural('group', 'Categoría')))->counts('groups'),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePrograms::route('/')];
    }
}
