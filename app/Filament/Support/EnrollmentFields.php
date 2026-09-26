<?php

namespace App\Filament\Support;

use App\Enums\EnrollmentStatus;
use App\Models\Group;
use App\Models\Season;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;

/**
 * Campos de una inscripción (grupo + temporada + estado), compartidos por el
 * relation manager del alumno y el recurso de inscripciones.
 */
class EnrollmentFields
{
    /**
     * @return array<int, mixed>
     */
    public static function make(): array
    {
        return [
            Select::make('group_id')
                ->label(Terms::label('group', 'Categoría'))
                ->relationship('group', 'name', fn ($query) => $query->with('program')->where('is_active', true))
                ->getOptionLabelFromRecordUsing(fn (Group $group) => "{$group->name} · {$group->program->name}")
                ->required()
                ->preload(),
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
        ];
    }
}
