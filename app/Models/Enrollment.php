<?php

namespace App\Models;

use App\Actions\Billing\GenerateEnrollmentCharge;
use App\Enums\EnrollmentStatus;
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
#[Fillable(['organization_id', 'student_id', 'group_id', 'season_id', 'status', 'enrolled_on', 'ended_on', 'notes'])]
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use BelongsToOrganization, HasFactory;

    protected $attributes = ['status' => 'pendiente'];

    protected static function booted(): void
    {
        static::creating(function (Enrollment $enrollment): void {
            $enrollment->organization_id ??= $enrollment->student?->organization_id;
        });

        // Cargo de inscripción, si hay tarifa (alta, Inscribir, pase de temporada, importación).
        static::created(fn (Enrollment $enrollment) => app(GenerateEnrollmentCharge::class)->handle($enrollment));

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
        ];
    }

    /**
     * Inscripciones de la temporada actual.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereHas('season', fn (Builder $season) => $season->where('is_current', true));
    }

    /**
     * Las que generan cuota: temporada actual, activo o becado (la beca total no se
     * cobra; la parcial se aplica como ajuste).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function billable(Builder $query): void
    {
        $query->current()->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Scholarship]);
    }

    /**
     * Se cierra sola: de una temporada que ya no es la actual (salvo las dadas de baja).
     */
    public function isFinished(): bool
    {
        return ! $this->season->is_current && $this->status !== EnrollmentStatus::Withdrawn;
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
