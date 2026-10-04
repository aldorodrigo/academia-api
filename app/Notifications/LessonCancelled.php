<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Support\Push\PushMessage;

/**
 * Aviso a la otra parte: se canceló una clase particular.
 */
class LessonCancelled extends PushNotification
{
    public string $body;

    public function __construct(Booking $booking, public bool $byTeacher)
    {
        $booking->loadMissing(['student', 'teacher', 'organization']);
        $day = LessonReminder::dayLabel($booking);
        $time = substr($booking->starts_at, 0, 5);
        $reason = filled($booking->cancel_reason) ? " ({$booking->cancel_reason})" : '';

        $this->body = $byTeacher
            ? "{$booking->teacher->name} canceló la clase de {$booking->student->first_name} de {$day} a las {$time}{$reason}. Podés reservar otro horario."
            : "{$booking->student->full_name} canceló la clase de {$day} a las {$time}.";
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage('Clase cancelada', $this->body, [
            'type' => 'lesson_cancelled',
            'route' => $this->byTeacher ? '/reservas' : '/particulares/agenda',
        ]);
    }
}
