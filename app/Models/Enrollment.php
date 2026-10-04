<?php

namespace App\Models;

use App\Actions\Billing\GenerateEnrollmentCharge;
use App\Actions\Billing\IssueSeasonCharges;
use App\Actions\Billing\VoidFutureCharges;
use App\Enums\EnrollmentStatus;
use App\Enums\MidPeriod;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inscripción = alumno + grupo + temporada. Cada una genera sus propios cargos (Sprint 3).
 */
#[Fillable(['organization_id', 'student_id', 'group_id', 'season_id', 'status', 'enrolled_on', 'ended_on', 'withdrawal_reason', 'withdrawn_by', 'dropout_reported_at', 'dropout_reported_by', 'dropout_note', 'mid_period', 'notes'])]
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use BelongsToOrganization, HasFactory;

    protected $attributes = ['status' => 'pendiente'];

    /**
     * En el pase de temporada y la importación las cuotas se crean en segundo plano.
     */
    public static bool $deferSeasonCharges = false;

    /**
     * Ejecuta el callback sin crear las cuotas de la temporada al inscribir.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutSeasonCharges(callable $callback): mixed
    {
        $previous = self::$deferSeasonCharges;
        self::$deferSeasonCharges = true;

        try {
            return $callback();
        } finally {
            self::$deferSeasonCharges = $previous;
        }
    }

    protected static function booted(): void
    {
        static::creating(function (Enrollment $enrollment): void {
            $enrollment->organization_id ??= $enrollment->student?->organization_id;
        });

        // Cargo de inscripción, si hay tarifa, y las cuotas de la temporada según su plan
        // (alta, Inscribir, pase de temporada, importación). En el pase se encolan.
        static::created(function (Enrollment $enrollment): void {
            app(GenerateEnrollmentCharge::class)->handle($enrollment);

            if (! self::$deferSeasonCharges) {
                app(IssueSeasonCharges::class)->forEnrollment($enrollment);
            }
        });

        // Baja o suspensión: se anulan las cuotas futuras sin pagar. Al reactivarla se emiten desde el
        // período en curso (los meses que estuvo afuera no se cobran) y se reemiten las futuras.
        static::updated(function (Enrollment $enrollment): void {
            if (! $enrollment->wasChanged('status')) {
                return;
            }

            $paused = [EnrollmentStatus::Withdrawn, EnrollmentStatus::Suspended];

            if (in_array($enrollment->status, $paused, true)) {
                app(VoidFutureCharges::class)->handle($enrollment);
            } elseif ($enrollment->isBillableStatus()) {
                $returns = in_array($enrollment->getOriginal('status'), $paused, true);

                app(IssueSeasonCharges::class)->forEnrollment($enrollment, from: $returns ? $enrollment->organization->today() : null);
            }
        });

        // Al pasar a baja queda registrada la fecha (y se cierra el aviso del técnico); al reactivarla
        // se limpian la fecha, el motivo y quién (queda en el registro de actividad).
        static::saving(function (Enrollment $enrollment): void {
            if (! $enrollment->isDirty('status')) {
                return;
            }

            if ($enrollment->status === EnrollmentStatus::Withdrawn) {
                $enrollment->ended_on ??= now()->toDateString();
                $enrollment->forceFill(['dropout_reported_at' => null, 'dropout_reported_by' => null, 'dropout_note' => null]);
            } else {
                $enrollment->forceFill(['ended_on' => null, 'withdrawal_reason' => null, 'withdrawn_by' => null]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => EnrollmentStatus::class,
            'enrolled_on' => 'date',
            'ended_on' => 'date',
            'dropout_reported_at' => 'datetime',
            'mid_period' => MidPeriod::class,
        ];
    }

    /**
     * Inscripciones de temporadas vigentes o próximas.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereHas('season', fn (Builder $season) => $season->open());
    }

    /**
     * Las que generan cuota: temporada vigente, activo o becado (la beca total no se
     * cobra; la parcial se aplica como ajuste).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function billable(Builder $query): void
    {
        $query->whereHas('season', fn (Builder $season) => $season->active())
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Scholarship]);
    }

    /**
     * Activo o becado: se le cobra la cuota.
     */
    public function isBillableStatus(): bool
    {
        return in_array($this->status, [EnrollmentStatus::Active, EnrollmentStatus::Scholarship], true);
    }

    /**
     * Qué se cobra del período en curso: lo que se eligió al inscribir o lo del plan.
     */
    public function midPeriod(): MidPeriod
    {
        return $this->mid_period ?? $this->season->mid_period ?? MidPeriod::Full;
    }

    /**
     * Se cierra sola: de una temporada que ya terminó (salvo las dadas de baja).
     */
    public function isFinished(): bool
    {
        return $this->season->hasEnded() && $this->status !== EnrollmentStatus::Withdrawn;
    }

    public function statusLabel(): string
    {
        return $this->isFinished() ? 'Finalizada' : $this->status->label();
    }

    public function statusColor(): string
    {
        return $this->isFinished() ? 'gray' : $this->status->getColor();
    }

    public function isWithdrawn(): bool
    {
        return $this->status === EnrollmentStatus::Withdrawn;
    }

    /**
     * El técnico avisó que dejó de venir y todavía no se decidió.
     */
    public function hasDropoutReport(): bool
    {
        return $this->dropout_reported_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function withdrawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function dropoutReportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dropout_reported_by');
    }

    /**
     * @return HasMany<Scholarship, $this>
     */
    public function scholarships(): HasMany
    {
        return $this->hasMany(Scholarship::class);
    }

    /**
     * @return HasMany<Charge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return BelongsTo<Season, $this>
     */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }
}
