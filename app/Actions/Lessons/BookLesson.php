<?php

namespace App\Actions\Lessons;

use App\Enums\BookingStatus;
use App\Enums\ClassPackStatus;
use App\Models\Booking;
use App\Models\ClassPack;
use App\Models\Family;
use App\Models\LessonProfile;
use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use App\Notifications\LessonBooked;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reserva una clase con un profesor si la hora está libre (se confirma sola). Usa una clase
 * del paquete activo si tiene libres y la fecha es hasta su vencimiento; si no, es suelta.
 */
class BookLesson
{
    public const TAKEN = 'Ese horario ya se reservó. Elegí otro.';

    public function __construct(private AvailableSlots $slots) {}

    public function handle(Organization $organization, LessonProfile $profile, Student $student, CarbonImmutable $date, string $startsAt, User $by): Booking
    {
        $startsAt = substr($startsAt, 0, 5);

        if (! $this->slots->isFree($profile, $organization, $date, $startsAt)) {
            throw ValidationException::withMessages(['starts_at' => self::TAKEN]);
        }

        // Los pagos y el saldo a favor son por familia (alumno adulto sin tutores: se crea).
        Family::ensureFor($student);

        try {
            $booking = DB::transaction(function () use ($organization, $profile, $student, $date, $startsAt, $by) {
                $pack = ClassPack::query()
                    ->where('student_id', $student->id)
                    ->where('user_id', $profile->user_id)
                    ->where('status', ClassPackStatus::Active)
                    ->orderBy('expires_on')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->first(fn (ClassPack $pack) => $pack->covers($date));

                $endsAt = AvailableSlots::time(AvailableSlots::minutes($startsAt) + $profile->duration_minutes);

                return Booking::query()->create([
                    'organization_id' => $organization->id,
                    'student_id' => $student->id,
                    'user_id' => $profile->user_id,
                    'date' => $date->toDateString(),
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'status' => BookingStatus::Confirmed,
                    'class_pack_id' => $pack?->id,
                    'price' => $profile->single_price,
                    'slot_key' => Booking::slotKey($profile->user_id, $date->toDateString(), $startsAt),
                    'booked_by' => $by->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['starts_at' => self::TAKEN]);
        }

        $profile->user?->notify(new LessonBooked($booking));

        return $booking;
    }
}
