<?php

namespace App\Filament\Resources\Students\RelationManagers;

use App\Filament\Support\EnrollmentForm;
use App\Filament\Support\Terms;
use App\Filament\Support\WithdrawalActions;
use App\Models\Enrollment;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EnrollmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'enrollments';

    protected static ?string $title = 'Inscripciones';

    protected static ?string $modelLabel = 'inscripción';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components(EnrollmentForm::fields($this->getOwnerRecord()));
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['group.program', 'season', 'student', 'organization', 'dropoutReportedBy']))
            ->columns([
                TextColumn::make('season.name')->label('Temporada')->sortable(),
                TextColumn::make('group.program.name')->label(Terms::label('program', 'Disciplina')),
                TextColumn::make('group.name')->label(Terms::label('group', 'Categoría')),
                TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn (Enrollment $record) => $record->statusLabel())
                    ->color(fn (Enrollment $record) => $record->statusColor())
                    ->description(fn (Enrollment $record) => $record->isWithdrawn() ? null : WithdrawalActions::description($record)),
                TextColumn::make('enrolled_on')->label('Desde')->date('d/m/Y'),
                TextColumn::make('ended_on')->label('Baja')->date('d/m/Y')->placeholder('—')
                    ->description(fn (Enrollment $record) => $record->withdrawal_reason),
            ])
            ->defaultSort('season_id', 'desc')
            ->headerActions([CreateAction::make()->label('Inscribir')])
            // Las de temporadas anteriores están finalizadas: quedan como historial.
            ->recordActions([
                WithdrawalActions::dismissDropout(),
                WithdrawalActions::withdraw(),
                WithdrawalActions::reactivate(),
                EditAction::make()->hidden(fn (Enrollment $record) => $record->isFinished()),
                DeleteAction::make()->hidden(fn (Enrollment $record) => $record->isFinished()),
            ]);
    }
}
