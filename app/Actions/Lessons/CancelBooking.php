<?php

namespace App\Actions\Lessons;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Notifications\LessonCancelled;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Cancela una reserva confirmada que todavía no empezó (sin costo) y avisa a la otra parte.
 */
class CancelBooking
{
    public function handle(Booking $booking, User $by, bool $byTeacher, ?string $reason = null): Booking
    {
        if (! $booking->canBeCancelled()) {
            throw ValidationException::withMessages(['booking' => 'Esta clase ya no se puede cancelar.']);
        }

        $booking->update([
            'status' => $byTeacher ? BookingStatus::CancelledByTeacher : BookingStatus::CancelledByStudent,
            'slot_key' => null,
            'cancelled_by' => $by->id,
            'cancelled_at' => now(),
            'cancel_reason' => filled($reason) ? trim($reason) : null,
        ]);

        $recipients = $byTeacher
            ? LessonAccess::recipients($booking->student)
            : collect([$booking->teacher])->filter();

        Notification::send($recipients, new LessonCancelled($booking, $byTeacher));

        return $booking;
    }
}
