<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reserva de una clase particular: un alumno con un profesor un día y hora.
 * Se paga con una clase del paquete (`class_pack_id`) o como clase suelta (`price`; el cargo
 * se emite cuando el profesor marca "Vino").
 */
#[Fillable(['organization_id', 'student_id', 'user_id', 'date', 'starts_at', 'ends_at', 'status', 'class_pack_id', 'price', 'charge_id', 'slot_key', 'booked_by', 'cancelled_by', 'cancelled_at', 'cancel_reason', 'marked_at'])]
class Booking extends Model
{
    use BelongsToOrganization;

    /** Días después de la clase en que el profesor todavía la puede marcar. */
    public const MARKABLE_DAYS = 3;

    protected $attributes = ['status' => 'confirmada'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'status' => BookingStatus::class,
            'price' => 'integer',
            'cancelled_at' => 'immutable_datetime',
            'marked_at' => 'immutable_datetime',
        ];
    }

    public static function slotKey(int $teacherId, string $date, string $startsAt): string
    {
        return "{$teacherId}:{$date}:".substr($startsAt, 0, 5);
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
     * @return BelongsTo<ClassPack, $this>
     */
    public function classPack(): BelongsTo
    {
        return $this->belongsTo(ClassPack::class);
    }

    /**
     * @return BelongsTo<Charge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function usesPack(): bool
    {
        return $this->class_pack_id !== null;
    }

    public function startsAt(): CarbonImmutable
    {
        return $this->at($this->starts_at);
    }

    private function at(string $time): CarbonImmutable
    {
        $timezone = $this->organization?->timezone ?? config('app.timezone');

        return CarbonImmutable::parse($this->date->toDateString().' '.substr($time, 0, 5), $timezone);
    }

    public function hasStarted(?CarbonImmutable $now = null): bool
    {
        return ! ($now ?? CarbonImmutable::now())->lt($this->startsAt());
    }

    public function canBeCancelled(?CarbonImmutable $now = null): bool
    {
        return $this->status === BookingStatus::Confirmed && ! $this->hasStarted($now);
    }

    /**
     * El profesor la marca desde el día de la clase hasta 3 días después.
     */
    public function isMarkable(CarbonImmutable $today): bool
    {
        if ($this->status->isCancelled()) {
            return false;
        }

        $date = $this->date->toDateString();

        return $date <= $today->toDateString() && $date >= $today->subDays(self::MARKABLE_DAYS)->toDateString();
    }

    /**
     * "06/10".
     */
    public function shortDate(): string
    {
        return $this->date->format('d/m');
    }
}
