<?php

namespace App\Filament\Resources\Students\RelationManagers;

use App\Models\Attendance;
use App\Models\Schedule;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Asistencia del jugador: clases tomadas y la respuesta del tutor. Solo lectura (se toma desde el grupo).
 */
class AttendancesRelationManager extends RelationManager
{
    protected static string $relationship = 'attendances';

    protected static ?string $title = 'Asistencia';

    /**
     * % de presentes del mes.
     */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $month = filament()->getTenant()->today()->startOfMonth();
        $marks = $ownerRecord->attendances()
            ->whereNotNull('status')
            ->whereHas('classSession', fn (Builder $query) => $query->whereDate('date', '>=', $month->toDateString()))
            ->get()
            ->countBy(fn (Attendance $attendance) => $attendance->status->value);
        $rate = Attendance::summary($marks->all())['rate'];

        return $rate === null ? null : "{$rate}% este mes";
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('classSession.group.program')
                ->join('class_sessions', 'class_sessions.id', '=', 'attendances.class_session_id')
                ->select('attendances.*')
                ->orderByDesc('class_sessions.date')
                ->orderByDesc('class_sessions.starts_at'))
            ->columns([
                TextColumn::make('classSession.date')->label('Fecha')->date('D d/m/Y'),
                TextColumn::make('classSession.starts_at')->label('Clase')
                    ->formatStateUsing(fn (Attendance $record) => $record->classSession->group->program->name.' '.$record->classSession->group->name.' · '.Schedule::time($record->classSession->starts_at)),
                TextColumn::make('status')->label('Asistencia')->badge()->placeholder('Sin tomar')
                    ->description(fn (Attendance $record) => $record->note),
                TextColumn::make('guardian_response')->label('Aviso del tutor')->placeholder('—'),
            ]);
    }
}
