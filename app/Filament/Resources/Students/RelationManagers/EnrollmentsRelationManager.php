<?php

namespace App\Filament\Resources\Students\RelationManagers;

use App\Filament\Support\EnrollmentFields;
use App\Filament\Support\Terms;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class EnrollmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'enrollments';

    protected static ?string $title = 'Inscripciones';

    protected static ?string $modelLabel = 'inscripción';

    public function form(Schema $schema): Schema
    {
        $fields = EnrollmentFields::make();
        // Una inscripción por alumno, grupo y temporada.
        $fields[0]->unique(
            ignoreRecord: true,
            modifyRuleUsing: fn (Unique $rule, callable $get) => $rule
                ->where('student_id', $this->getOwnerRecord()->getKey())
                ->where('season_id', $get('season_id')),
        )->validationMessages(['unique' => 'Ya está inscripto en ese grupo esta temporada.']);

        return $schema->columns(2)->components($fields);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['group.program', 'season']))
            ->columns([
                TextColumn::make('season.name')->label('Temporada')->sortable(),
                TextColumn::make('group.program.name')->label(Terms::label('program', 'Disciplina')),
                TextColumn::make('group.name')->label(Terms::label('group', 'Categoría')),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('enrolled_on')->label('Desde')->date('d/m/Y'),
                TextColumn::make('ended_on')->label('Baja')->date('d/m/Y')->placeholder('—'),
            ])
            ->defaultSort('season_id', 'desc')
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
