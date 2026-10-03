<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Support\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Push al alumno o tutor antes de una clase particular reservada.
 */
class LessonReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public string $body;

    public function __construct(Booking $booking, bool $self)
    {
        $booking->loadMissing(['student', 'teacher', 'organization']);
        $day = ucfirst(self::dayLabel($booking));
        $time = substr($booking->starts_at, 0, 5);
        $who = $self ? 'tenés' : "{$booking->student->first_name} tiene";

        $this->body = "{$day} {$who} clase con {$booking->teacher->name} a las {$time}.";
    }

    /**
     * "hoy", "mañana" o "el 6/10".
     */
    public static function dayLabel(Booking $booking): string
    {
        return ClassReminder::dayLabel($booking->date, $booking->organization->today());
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['push'];
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage('Clase particular', $this->body, ['type' => 'lesson_reminder', 'route' => '/reservas']);
    }
}
