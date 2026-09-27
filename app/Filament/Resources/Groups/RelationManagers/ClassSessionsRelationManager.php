<?php

namespace App\Filament\Resources\Groups\RelationManagers;

use App\Actions\Attendance\RecordAttendance;
use App\Actions\Attendance\ResolveClassSessions;
use App\Actions\Attendance\SuspendClass;
use App\Enums\AttendanceStatus;
use App\Enums\GuardianResponse;
use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\Student;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Clases del grupo: asistencia (se puede corregir siempre desde el panel) y suspensión.
 */
class ClassSessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'classSessions';

    protected static ?string $title = 'Clases y asistencia';

    protected static ?string $modelLabel = 'clase';

    public function mount(): void
    {
        parent::mount();

        // Las clases se crean a partir de los horarios: el último mes y la próxima semana.
        $today = filament()->getTenant()->today();
        app(ResolveClassSessions::class)->between([$this->getOwnerRecord()], $today->subMonth(), $today->addWeek());
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['venue', 'organization']))
            ->columns([
                TextColumn::make('date')->label('Fecha')->date('D d/m/Y')->sortable(),
                TextColumn::make('starts_at')->label('Horario')
                    ->formatStateUsing(fn (ClassSession $record) => Schedule::time($record->starts_at).'–'.Schedule::time($record->ends_at)),
                TextColumn::make('venue.name')->label('Cancha')->placeholder('—'),
                TextColumn::make('status')->label('Estado')->badge()
                    ->description(fn (ClassSession $record) => $record->suspension_reason),
                TextColumn::make('attendance')->label('Asistencia')
                    ->state(function (ClassSession $record) {
                        if (! $record->isAttendanceTaken()) {
                            return $record->isSuspended() ? null : 'Sin tomar';
                        }
                        $counts = $record->counts();

                        return "{$counts['present']} presentes · {$counts['absent']} ausentes · {$counts['justified']} justificados";
                    })
                    ->placeholder('—'),
            ])
            ->defaultSort('date', 'desc')
            ->recordActions([
                Action::make('attendance')
                    ->label(fn (ClassSession $record) => $record->isAttendanceTaken() ? 'Corregir asistencia' : 'Tomar asistencia')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->visible(fn (ClassSession $record) => ! $record->isSuspended() && ! $record->date->isFuture())
                    ->modalWidth('2xl')
                    ->schema(fn (ClassSession $record) => $this->attendanceFields($record))
                    ->fillForm(fn (ClassSession $record) => $this->attendanceState($record))
                    ->action(function (ClassSession $record, array $data, RecordAttendance $recordAttendance) {
                        $marks = collect($data['marks'] ?? [])
                            ->map(fn ($status, $studentId) => ['student_id' => (int) $studentId, 'status' => $status instanceof AttendanceStatus ? $status->value : $status])
                            ->values()
                            ->all();
                        $recordAttendance->handle($record, $marks, auth()->user(), fromPanel: true);
                        Notification::make()->title('Asistencia guardada')->success()->send();
                    }),
                Action::make('suspend')
                    ->label('Suspender')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (ClassSession $record) => ! $record->isSuspended() && ! $record->isPast())
                    ->schema([
                        Radio::make('reason')->label('Motivo')->options(['Lluvia' => 'Lluvia', 'Cancha ocupada' => 'Cancha ocupada', 'Otro' => 'Otro'])
                            ->default('Lluvia')->required()->live(),
                        TextInput::make('other')->label('¿Cuál?')->maxLength(120)
                            ->visible(fn ($get) => $get('reason') === 'Otro')->required(fn ($get) => $get('reason') === 'Otro'),
                    ])
                    ->modalDescription('Se avisa por push a los tutores del grupo.')
                    ->action(function (ClassSession $record, array $data, SuspendClass $suspend) {
                        $suspend->handle($record, $data['reason'] === 'Otro' ? $data['other'] : $data['reason'], auth()->user());
                        Notification::make()->title('Clase suspendida')->body('Se avisó a los tutores.')->success()->send();
                    }),
                Action::make('resume')
                    ->label('Volver a programar')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (ClassSession $record) => $record->isSuspended() && ! $record->isPast())
                    ->requiresConfirmation()
                    ->action(fn (ClassSession $record, SuspendClass $suspend) => $suspend->resume($record)),
            ]);
    }

    /**
     * @return list<ToggleButtons>
     */
    private function attendanceFields(ClassSession $session): array
    {
        $responses = $session->attendances()->pluck('guardian_response', 'student_id');

        return $session->students()->map(fn (Student $student) => ToggleButtons::make("marks.{$student->id}")
            ->label($student->full_name)
            ->helperText($responses->get($student->id) === GuardianResponse::NotGoing ? 'El tutor avisó que no va.' : null)
            ->options(AttendanceStatus::class)
            ->inline()
            ->required())
            ->all();
    }

    /**
     * Lo guardado; si no se tomó, presentes salvo los que avisaron que no van.
     *
     * @return array{marks: array<int, string>}
     */
    private function attendanceState(ClassSession $session): array
    {
        $attendances = $session->attendances()->get()->keyBy('student_id');

        return ['marks' => $session->students()->mapWithKeys(function (Student $student) use ($attendances) {
            /** @var Attendance|null $attendance */
            $attendance = $attendances->get($student->id);
            $status = $attendance?->status
                ?? ($attendance?->guardian_response === GuardianResponse::NotGoing ? AttendanceStatus::Justified : AttendanceStatus::Present);

            return [$student->id => $status->value];
        })->all()];
    }
}
