<?php

namespace App\Filament\Resources\Groups\RelationManagers;

use App\Actions\Attendance\RecordAttendance;
use App\Actions\Attendance\RescheduleClass;
use App\Actions\Attendance\ResolveClassSessions;
use App\Actions\Attendance\SuspendClass;
use App\Enums\AttendanceStatus;
use App\Enums\GuardianResponse;
use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\Venue;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
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
            ->modifyQueryUsing(fn ($query) => $query->with(['venue', 'organization', 'rescheduledTo']))
            ->columns([
                TextColumn::make('date')->label('Fecha')->date('D d/m/Y')->sortable(),
                TextColumn::make('starts_at')->label('Horario')
                    ->formatStateUsing(fn (ClassSession $record) => Schedule::time($record->starts_at).'–'.Schedule::time($record->ends_at)),
                TextColumn::make('venue.name')->label('Cancha')->placeholder('—'),
                TextColumn::make('status')->label('Estado')->badge()
                    ->description(fn (ClassSession $record) => collect([
                        $record->is_makeup ? 'Recuperación' : null,
                        $record->isRescheduled() && $record->rescheduledTo
                            ? 'Pasó al '.$record->rescheduledTo->date->format('d/m').' '.Schedule::time($record->rescheduledTo->starts_at)
                            : null,
                        $record->suspension_reason,
                        $record->charge_waived ? 'No se cobra' : null,
                    ])->filter()->join(' · ') ?: null),
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
                    ->visible(fn (ClassSession $record) => ! $record->isOff() && ! $record->date->isFuture())
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
                    ->visible(fn (ClassSession $record) => ! $record->isOff() && ! $record->isPast())
                    ->schema(fn (ClassSession $record) => [
                        Radio::make('reason')->label('Motivo')->options(['Lluvia' => 'Lluvia', 'Cancha ocupada' => 'Cancha ocupada', 'Otro' => 'Otro'])
                            ->default('Lluvia')->required()->live(),
                        TextInput::make('other')->label('¿Cuál?')->maxLength(120)
                            ->visible(fn ($get) => $get('reason') === 'Otro')->required(fn ($get) => $get('reason') === 'Otro'),
                        Radio::make('then')->label('¿Qué pasa con la clase?')
                            ->options(['cancel' => 'Cancelar la clase (no se recupera)', 'reschedule' => 'Reprogramar (se recupera otro día u horario)'])
                            ->default('cancel')->required()->live(),
                        Checkbox::make('waive_charge')->label('No cobrar esta clase')
                            ->helperText('Se descuenta de la cuota por día de entrenamiento.')
                            ->default(true)
                            ->visible(fn ($get) => $get('then') === 'cancel' && $record->canWaiveCharge()),
                        ...self::rescheduleFields($record, fn ($get) => $get('then') === 'reschedule'),
                    ])
                    ->modalDescription('Se avisa por push a los tutores del grupo.')
                    ->action(function (ClassSession $record, array $data, SuspendClass $suspend, RescheduleClass $reschedule) {
                        $reason = $data['reason'] === 'Otro' ? $data['other'] : $data['reason'];

                        if ($data['then'] === 'reschedule') {
                            $reschedule->handle($record, [...self::rescheduleData($data), 'reason' => $reason], auth()->user());
                            Notification::make()->title('Clase reprogramada')->body('Se avisó a los tutores.')->success()->send();

                            return;
                        }

                        $suspend->handle($record, $reason, auth()->user(), (bool) ($data['waive_charge'] ?? false));
                        Notification::make()->title('Clase suspendida')->body('Se avisó a los tutores.')->success()->send();
                    }),
                Action::make('move')
                    ->label('Cambiar día u horario')
                    ->icon('heroicon-o-calendar-days')
                    ->visible(fn (ClassSession $record) => ! $record->isRescheduled() && ! $record->isPast())
                    ->schema(fn (ClassSession $record) => self::rescheduleFields($record))
                    ->modalDescription('Se avisa por push a los tutores del grupo.')
                    ->action(function (ClassSession $record, array $data, RescheduleClass $reschedule) {
                        $reschedule->handle($record, [...self::rescheduleData($data), 'reason' => $record->suspension_reason], auth()->user());
                        Notification::make()->title('Clase reprogramada')->body('Se avisó a los tutores.')->success()->send();
                    }),
                Action::make('resume')
                    ->label('Volver a programar')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (ClassSession $record) => $record->isSuspended() && ! $record->isPast())
                    ->requiresConfirmation()
                    ->action(fn (ClassSession $record, SuspendClass $suspend) => $suspend->resume($record)),
                Action::make('cancel_reschedule')
                    ->label('Cancelar reprogramación')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->visible(fn (ClassSession $record) => $record->isRescheduled() && $record->rescheduledTo !== null)
                    ->requiresConfirmation()
                    ->modalDescription('Se borra la clase de recuperación y se avisa a los tutores.')
                    ->action(function (ClassSession $record, RescheduleClass $reschedule) {
                        $reschedule->cancel($record, auth()->user());
                        Notification::make()->title('Se canceló la reprogramación')->success()->send();
                    }),
            ]);
    }

    /**
     * Nuevo día, horario y cancha (por defecto, el día siguiente con el mismo horario).
     *
     * @return list<mixed>
     */
    private static function rescheduleFields(ClassSession $session, ?Closure $visible = null): array
    {
        $fields = [
            DatePicker::make('date')->label('Nuevo día')->native(false)->displayFormat('d/m/Y')
                ->default($session->date->addDay()->toDateString())
                ->minDate(filament()->getTenant()->today()->toDateString())->required(),
            TimePicker::make('starts_at')->label('Empieza')->seconds(false)->default(Schedule::time($session->starts_at))->required(),
            TimePicker::make('ends_at')->label('Termina')->seconds(false)->default(Schedule::time($session->ends_at))->required(),
            Select::make('venue_id')->label('Cancha')->options(fn () => Venue::query()->orderBy('name')->pluck('name', 'id'))
                ->default($session->venue_id),
        ];

        return $visible === null ? $fields : array_map(fn ($field) => $field->visible($visible)->required(fn ($get) => $visible($get) && $field->getName() !== 'venue_id'), $fields);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{date: string, starts_at: string, ends_at: string, venue_id: ?int}
     */
    private static function rescheduleData(array $data): array
    {
        return [
            'date' => substr((string) $data['date'], 0, 10),
            'starts_at' => substr((string) $data['starts_at'], 0, 5),
            'ends_at' => substr((string) $data['ends_at'], 0, 5),
            'venue_id' => filled($data['venue_id'] ?? null) ? (int) $data['venue_id'] : null,
        ];
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
