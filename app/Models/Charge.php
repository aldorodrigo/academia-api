<?php

namespace App\Models;

use App\Enums\ChargeStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ChargeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Cargo de la cuenta corriente de un alumno. Inmutable: no se edita ni se borra;
 * se anula con motivo (VoidCharge) y, si hace falta, se carga uno nuevo.
 */
#[Fillable(['organization_id', 'student_id', 'enrollment_id', 'group_id', 'season_id', 'fee_concept_id', 'tariff_id', 'period', 'period_start', 'period_end', 'description', 'base_amount', 'quantity', 'unit_amount', 'final_amount', 'issued_on', 'due_on', 'unique_key', 'voided_at', 'void_reason', 'voided_by', 'created_by'])]
class Charge extends Model
{
    /** @use HasFactory<ChargeFactory> */
    use BelongsToOrganization, HasFactory, LogsActivity;

    /** Lo único que cambia después de emitido: la anulación. */
    private const VOID_FIELDS = ['voided_at', 'void_reason', 'voided_by', 'updated_at'];

    /** Mientras se agrega o quita un ajuste de clase suspendida (ver withSuspendedClassAdjustment). */
    private static bool $adjusting = false;

    /**
     * Única excepción a "un cargo no se modifica": el monto final de una cuota impaga cambia
     * por el ajuste "Clase suspendida" (queda en la auditoría). Ver WaiveSuspendedClass.
     */
    public static function withSuspendedClassAdjustment(callable $callback): mixed
    {
        self::$adjusting = true;

        try {
            return $callback();
        } finally {
            self::$adjusting = false;
        }
    }

    protected static function booted(): void
    {
        static::updating(function (Charge $charge): void {
            // Al anular se puede liberar la clave para volver a emitir el período.
            $allowed = match (true) {
                $charge->isDirty('voided_at') => [...self::VOID_FIELDS, 'unique_key'],
                self::$adjusting => ['final_amount', 'updated_at'],
                default => self::VOID_FIELDS,
            };

            if (array_diff(array_keys($charge->getDirty()), $allowed) !== []) {
                throw new LogicException('Un cargo no se modifica: anulalo y cargá uno nuevo.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Un cargo no se borra: se anula con motivo.');
        });
    }

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'quantity' => 'integer',
            'unit_amount' => 'integer',
            'base_amount' => 'integer',
            'final_amount' => 'integer',
            'issued_on' => 'date',
            'due_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['description', 'final_amount', 'voided_at', 'void_reason'])
            ->logOnlyDirty()
            ->useLogName('billing');
    }

    /**
     * Estado calculado: anulado, vencido (pasó el vencimiento más los días de
     * gracia, en fecha local de la organización), pagado (no falta nada) o pendiente.
     */
    public function status(): ChargeStatus
    {
        if ($this->voided_at !== null) {
            return ChargeStatus::Voided;
        }

        if ($this->pendingAmount() === 0) {
            return ChargeStatus::Paid;
        }

        $organization = $this->organization;
        $graceDays = (int) $organization->billing('grace_days');

        // Fechas como texto: `due_on` no tiene zona horaria y hoy es la fecha local de la organización.
        return $organization->today()->toDateString() > $this->due_on->copy()->addDays($graceDays)->toDateString()
            ? ChargeStatus::Overdue
            : ChargeStatus::Pending;
    }

    /**
     * Imputaciones de pagos no anulados.
     *
     * @return Collection<int, PaymentAllocation>
     */
    public function activeAllocations(): Collection
    {
        $allocations = $this->relationLoaded('allocations')
            ? $this->allocations
            : $this->allocations()->with('payment')->get();

        return $allocations->filter(fn (PaymentAllocation $allocation) => ! $allocation->payment->isVoided())->values();
    }

    /**
     * Lo cubierto por pagos no anulados (incluye el pronto pago).
     */
    public function paidAmount(): int
    {
        return (int) $this->activeAllocations()->sum(fn (PaymentAllocation $allocation) => $allocation->covered());
    }

    public function pendingAmount(): int
    {
        return max(0, $this->final_amount - $this->paidAmount());
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->orderBy('id');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function notVoided(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Próxima: falta pagar algo y su período todavía no empezó (cuotas creadas por
     * adelantado) o, si no tiene período (inscripción), su temporada todavía no empezó.
     */
    public function isUpcoming(): bool
    {
        if ($this->voided_at !== null) {
            return false;
        }

        $today = $this->organization->today();
        $startsOn = $this->period_start ?? $this->season?->starts_on;

        return $startsOn !== null && $startsOn->gt($today) && $this->pendingAmount() > 0;
    }

    /**
     * @return BelongsTo<Season, $this>
     */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /**
     * @return BelongsTo<FeeConcept, $this>
     */
    public function feeConcept(): BelongsTo
    {
        return $this->belongsTo(FeeConcept::class);
    }

    /**
     * @return BelongsTo<Tariff, $this>
     */
    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    /**
     * Reservas de clases particulares sueltas cobradas con este cargo.
     *
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Paquetes de clases que se pagan con este cargo.
     *
     * @return HasMany<ClassPack, $this>
     */
    public function classPacks(): HasMany
    {
        return $this->hasMany(ClassPack::class);
    }

    /**
     * @return HasMany<ChargeAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(ChargeAdjustment::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
