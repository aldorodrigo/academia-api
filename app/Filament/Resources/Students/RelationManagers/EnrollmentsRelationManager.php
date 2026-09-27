<?php

namespace App\Filament\Resources\Students\RelationManagers;

use App\Enums\EnrollmentStatus;
use App\Filament\Support\Terms;
use App\Models\Group;
use App\Models\Season;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
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

    /**
     * Único formulario de inscripción: se inscribe desde la ficha del jugador.
     */
    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('group_id')
                ->label(Terms::label('group', 'Categoría'))
                ->relationship('group', 'name', fn (Builder $query) => $query->with('program')->where('is_active', true))
                ->getOptionLabelFromRecordUsing(fn (Group $group) => "{$group->name} · {$group->program->name}")
                ->required()
                ->preload()
                // Una inscripción por jugador, grupo y temporada.
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                        ->where('student_id', $this->getOwnerRecord()->getKey())
                        ->where('season_id', $get('season_id')),
                )
                ->validationMessages(['unique' => 'Ya está inscripto en ese grupo esta temporada.']),
            Select::make('season_id')
                ->label('Temporada')
                ->relationship('season', 'name')
                ->default(fn () => Season::currentOrNull()?->id)
                ->required(),
            Select::make('status')
                ->label('Estado')
                ->options(EnrollmentStatus::class)
                ->default(EnrollmentStatus::Active)
                ->required(),
            DatePicker::make('enrolled_on')->label('Fecha de inscripción')->default(now()),
            Textarea::make('notes')->label('Notas')->columnSpanFull(),
        ]);
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
