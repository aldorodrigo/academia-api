<?php

namespace App\Filament\Resources\Students\Tables;

use App\Enums\EnrollmentStatus;
use App\Filament\Support\Terms;
use App\Models\Enrollment;
use App\Models\Student;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StudentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('currentEnrollments.group'))
            ->columns([
                TextColumn::make('last_name')->label('Apellido')->searchable()->sortable(),
                TextColumn::make('first_name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('document')->label('Documento')->searchable()->toggleable(),
                TextColumn::make('birth_date')->label('Nacimiento')->date('d/m/Y')->sortable()
                    ->description(fn (Student $record) => $record->birth_date?->age.' años'),
                TextColumn::make('groups')
                    ->label(Terms::label('group', 'Categoría'))
                    ->state(fn (Student $record) => $record->currentEnrollments->map(fn (Enrollment $e) => $e->group->name)->all())
                    ->badge(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->state(fn (Student $record) => $record->currentEnrollments->map(fn (Enrollment $e) => $e->status)->all())
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('group')
                    ->label(Terms::label('group', 'Categoría'))
                    ->relationship('currentEnrollments.group', 'name')
                    ->preload(),
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(EnrollmentStatus::class)
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'],
                        fn (Builder $query, string $status) => $query->whereHas('currentEnrollments', fn (Builder $e) => $e->where('status', $status)),
                    )),
                TernaryFilter::make('enrolled')
                    ->label('Inscripto en la temporada actual')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('currentEnrollments'),
                        false: fn (Builder $query) => $query->whereDoesntHave('currentEnrollments'),
                    ),
            ])
            ->defaultSort('last_name')
            ->recordActions([EditAction::make()]);
    }
}
