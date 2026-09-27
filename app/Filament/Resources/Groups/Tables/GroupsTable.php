<?php

namespace App\Filament\Resources\Groups\Tables;

use App\Enums\EnrollmentStatus;
use App\Filament\Resources\Groups\Schemas\GroupForm;
use App\Filament\Support\Terms;
use App\Models\Group;
use App\Models\Schedule;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['program', 'schedules', 'instructors'])
                ->withCount(['enrollments as students_count' => fn (Builder $enrollments) => $enrollments
                    ->where('status', '!=', EnrollmentStatus::Withdrawn)
                    ->whereHas('season', fn (Builder $season) => $season->where('is_current', true))]))
            ->columns([
                TextColumn::make('program.name')->label(Terms::label('program', 'Disciplina'))->sortable()
                    ->visible(fn () => GroupForm::onlyProgram() === null),
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('criterion')
                    ->label('Edades / nivel')
                    ->state(fn (Group $record) => $record->level ?? match (true) {
                        $record->min_age && $record->max_age => "{$record->min_age} a {$record->max_age} años",
                        (bool) $record->max_age => "hasta {$record->max_age} años",
                        default => null,
                    }),
                TextColumn::make('schedules_summary')
                    ->label('Horarios')
                    ->state(fn (Group $record) => $record->schedules
                        ->map(fn (Schedule $s) => mb_substr(Schedule::WEEKDAYS[$s->weekday], 0, 3).' '.Schedule::time($s->starts_at))
                        ->all())
                    ->badge(),
                TextColumn::make('instructors.name')->label(ucfirst(Terms::plural('instructor', 'Técnico')))->listWithLineBreaks(),
                TextColumn::make('students_count')->label('Inscriptos')->sortable()
                    ->state(fn (Group $record) => $record->capacity ? "{$record->students_count} / {$record->capacity}" : $record->students_count),
                IconColumn::make('is_active')->label('Activo')->boolean(),
            ])
            ->filters([
                SelectFilter::make('program')->label(Terms::label('program', 'Disciplina'))->relationship('program', 'name')
                    ->visible(fn () => GroupForm::onlyProgram() === null),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
