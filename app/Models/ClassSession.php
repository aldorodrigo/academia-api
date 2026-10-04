<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\ClassStatus;
use App\Enums\DailyBasis;
use App\Enums\EnrollmentStatus;
use App\Enums\FeeFrequency;
use App\Enums\GuardianResponse;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Clase: un día concreto de un horario del grupo. Se crea sola al consultarla
 * (ver ResolveClassSessions).
 */
#[Fillable(['organization_id', 'group_id', 'date', 'starts_at', 'ends_at', 'venue_id', 'status', 'suspension_reason', 'suspended_by', 'charge_waived', 'rescheduled_to_id', 'is_makeup', 'attendance_taken_at', 'attendance_taken_by'])]
class ClassSession extends Model
{
    use BelongsToOrganization;

    /** Días después de la clase en que el técnico todavía la puede corregir desde la app. */
    public const EDITABLE_DAYS = 3;

    protected $attributes = ['status' => 'programada'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'status' => ClassStatus::class,
            'attendance_taken_at' => 'immutable_datetime',
            'charge_waived' => 'boolean',
            'is_makeup' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * La recuperación (si se reprogramó).
     *
     * @return BelongsTo<ClassSession, $this>
     */
    public function rescheduledTo(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'rescheduled_to_id');
    }

    /**
     * La original (si esta es una recuperación).
     *
     * @return HasOne<ClassSession, $this>
     */
    public function rescheduledFrom(): HasOne
    {
        return $this->hasOne(ClassSession::class, 'rescheduled_to_id');
    }

    /**
     * @return HasMany<Attendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === ClassStatus::Suspended;
    }

    public function isRescheduled(): bool
    {
        return $this->status === ClassStatus::Rescheduled;
    }

    /**
     * No se dicta en su día y hora: suspendida o reprogramada.
     */
    public function isOff(): bool
    {
        return $this->isSuspended() || $this->isRescheduled();
    }

    /**
     * Temporadas vigentes ese día que cobran por día de entrenamiento (donde una
     * suspensión puede no cobrarse).
     *
     * @return Builder<Season>
     */
    public function trainingDaySeasons(): Builder
    {
        return Season::query()
            ->active($this->date)
            ->forProgram($this->group->program_id)
            ->where('fee_frequency', FeeFrequency::Daily)
            ->where('daily_basis', DailyBasis::Training);
    }

    public function canWaiveCharge(): bool
    {
        return $this->trainingDaySeasons()->exists();
    }

    public function isAttendanceTaken(): bool
    {
        return $this->attendance_taken_at !== null;
    }

    /**
     * Inicio y fin en la hora local de la organización.
     */
    public function startsAt(): CarbonImmutable
    {
        return $this->at($this->starts_at);
    }

    public function endsAt(): CarbonImmutable
    {
        return $this->at($this->ends_at);
    }

    private function at(string $time): CarbonImmutable
    {
        $timezone = $this->organization?->timezone ?? config('app.timezone');

        return CarbonImmutable::parse($this->date->toDateString().' '.$time, $timezone);
    }

    /**
     * La clase es de un día anterior a hoy (fecha local).
     */
    public function isPast(?CarbonImmutable $today = null): bool
    {
        return $this->date->toDateString() < ($today ?? $this->organization->today())->toDateString();
    }

    public function hasStarted(?CarbonImmutable $now = null): bool
    {
        return ! ($now ?? CarbonImmutable::now())->lt($this->startsAt());
    }

    /**
     * El técnico la puede tomar o corregir: el día de la clase y hasta 3 días después.
     */
    public function isEditable(?CarbonImmutable $today = null): bool
    {
        $today ??= $this->organization->today();
        $date = $this->date->toDateString();

        // Por fecha (sin hora): la fecha de la clase y "hoy" están en zonas distintas.
        return $date <= $today->toDateString() && $date >= $today->subDays(self::EDITABLE_DAYS)->toDateString();
    }

    /**
     * Inscripciones que cuentan en la clase: activas o becadas, de una temporada
     * vigente ese día y ya inscriptas.
     *
     * @return Builder<Enrollment>
     */
    public function enrollmentsQuery(): Builder
    {
        $date = $this->date->toDateString();

        return Enrollment::query()
            ->where('group_id', $this->group_id)
            // Activos, becados y los nuevos que pidieron lugar desde la app y esperan la confirmación del club.
            ->where(fn (Builder $query) => $query
                ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Scholarship])
                ->orWhere(fn (Builder $pending) => $pending
                    ->where('status', EnrollmentStatus::Pending)
                    ->whereIn('id', EnrollmentRequest::query()->pending()->whereNotNull('enrollment_id')->select('enrollment_id'))))
            ->whereHas('season', fn (Builder $season) => $season->active($this->date))
            ->where(fn (Builder $query) => $query->whereNull('enrolled_on')->orWhereDate('enrolled_on', '<=', $date))
            ->where(fn (Builder $query) => $query->whereNull('ended_on')->orWhereDate('ended_on', '>=', $date));
    }

    /**
     * Alumnos de la clase por apellido: los inscriptos y los que ya tienen marca.
     *
     * @return Collection<int, Student>
     */
    public function students(): Collection
    {
        $marked = $this->attendances()->whereNotNull('status')->pluck('student_id');

        return Student::query()
            ->where(fn (Builder $query) => $query
                ->whereIn('id', $this->enrollmentsQuery()->select('student_id'))
                ->orWhereIn('id', $marked))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * Contadores para la app.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $students = $this->students()->pluck('id');
        $attendances = $this->attendances()->whereIn('student_id', $students)->get();
        $responses = $attendances->pluck('guardian_response');
        $statuses = $attendances->pluck('status');

        $going = $responses->filter(fn ($r) => $r === GuardianResponse::Going)->count();
        $notGoing = $responses->filter(fn ($r) => $r === GuardianResponse::NotGoing)->count();

        return [
            'enrolled' => $students->count(),
            'going' => $going,
            'not_going' => $notGoing,
            'no_answer' => $students->count() - $going - $notGoing,
            'present' => $statuses->filter(fn ($s) => $s === AttendanceStatus::Present)->count(),
            'absent' => $statuses->filter(fn ($s) => $s === AttendanceStatus::Absent)->count(),
            'justified' => $statuses->filter(fn ($s) => $s === AttendanceStatus::Justified)->count(),
        ];
    }
}
