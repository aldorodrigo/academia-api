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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Inscripción = alumno + grupo + temporada. Cada una genera sus propios cargos (Sprint 3).
 */
#[Fillable(['organization_id', 'student_id', 'group_id', 'season_id', 'status', 'enrolled_on', 'ended_on', 'mid_period', 'notes'])]
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

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
        // (alta, Inscribir, pase de temporada, importación). En el pase se encolan. Una pendiente
        // (ej. pedida desde la app) va a clases pero no se cobra hasta que se confirma.
        static::created(function (Enrollment $enrollment): void {
            if ($enrollment->status === EnrollmentStatus::Pending) {
                return;
            }

            app(GenerateEnrollmentCharge::class)->handle($enrollment);

            if (! self::$deferSeasonCharges) {
                app(IssueSeasonCharges::class)->forEnrollment($enrollment);
            }
        });

        // Baja o suspensión: se anulan las cuotas futuras sin pagar. Al reactivarla se reemiten.
        static::updated(function (Enrollment $enrollment): void {
            if (! $enrollment->wasChanged('status')) {
                return;
            }

            if (in_array($enrollment->status, [EnrollmentStatus::Withdrawn, EnrollmentStatus::Suspended], true)) {
                app(VoidFutureCharges::class)->handle($enrollment);
            } elseif ($enrollment->isBillableStatus()) {
                // Al confirmar una pendiente: el cargo de inscripción (una sola vez, idempotente).
                if ($enrollment->getOriginal('status') === EnrollmentStatus::Pending) {
                    app(GenerateEnrollmentCharge::class)->handle($enrollment);
                }

                app(IssueSeasonCharges::class)->forEnrollment($enrollment);
            }
        });

        // Al pasar a baja queda registrada la fecha; al reactivarla se limpia.
        static::saving(function (Enrollment $enrollment): void {
            if ($enrollment->isDirty('status')) {
                $enrollment->ended_on = $enrollment->status === EnrollmentStatus::Withdrawn
                    ? ($enrollment->ended_on ?? now()->toDateString())
                    : null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => EnrollmentStatus::class,
            'enrolled_on' => 'date',
            'ended_on' => 'date',
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
