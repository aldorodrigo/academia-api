<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\ClassPackStatus;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Paquete de clases comprado por un alumno a un profesor. Se guarda como cantidad de clases:
 * se activa cuando su cargo queda pagado, cada "Vino" usa una y vence a los `valid_days`
 * de activarse (null = sin vencimiento).
 */
#[Fillable(['organization_id', 'student_id', 'user_id', 'lesson_pack_id', 'charge_id', 'classes', 'used', 'price', 'valid_days', 'status', 'activated_on', 'expires_on', 'expiry_notified', 'created_by'])]
class ClassPack extends Model
{
    use BelongsToOrganization, LogsActivity;

    protected $attributes = ['status' => 'pendiente_pago', 'used' => 0];

    protected function casts(): array
    {
        return [
            'status' => ClassPackStatus::class,
            'classes' => 'integer',
            'used' => 'integer',
            'price' => 'integer',
            'valid_days' => 'integer',
            'activated_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'expiry_notified' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'used', 'activated_on', 'expires_on'])
            ->logOnlyDirty()
            ->useLogName('billing');
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Charge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function isActive(): bool
    {
        return $this->status === ClassPackStatus::Active;
    }

    /**
     * Reservas confirmadas que van a usar el paquete.
     */
    public function reserved(): int
    {
        return $this->bookings()->where('status', BookingStatus::Confirmed)->count();
    }

    /**
     * Clases sin usar (reservadas o no).
     */
    public function remaining(): int
    {
        return max(0, $this->classes - $this->used);
    }

    /**
     * Clases que todavía se pueden reservar.
     */
    public function available(): int
    {
        return max(0, $this->remaining() - $this->reserved());
    }

    /**
     * Una clase de ese día se puede pagar con el paquete.
     */
    public function covers(CarbonInterface $date): bool
    {
        return $this->isActive()
            && $this->available() > 0
            && ($this->expires_on === null || ! $date->copy()->startOfDay()->gt($this->expires_on));
    }

    /**
     * Activa el paquete (su cargo quedó pagado): el vencimiento corre desde hoy.
     */
    public function activate(CarbonImmutable $today): void
    {
        $this->update([
            'status' => ClassPackStatus::Active,
            'activated_on' => $today->toDateString(),
            'expires_on' => $this->valid_days === null ? null : $today->addDays($this->valid_days - 1)->toDateString(),
        ]);
    }

    /**
     * "Paquete 4 clases".
     */
    public function label(): string
    {
        return 'Paquete '.($this->classes === 1 ? '1 clase' : "{$this->classes} clases");
    }
}
