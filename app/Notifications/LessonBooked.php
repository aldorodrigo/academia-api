<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Support\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Push al profesor: le reservaron una clase.
 */
class LessonBooked extends Notification implements ShouldQueue
{
    use Queueable;

    public string $body;

    public function __construct(Booking $booking)
    {
        $booking->loadMissing(['student', 'organization']);
        $day = LessonReminder::dayLabel($booking);
        $payment = $booking->usesPack() ? 'con el paquete' : 'clase suelta';

        $this->body = "{$booking->student->full_name}, {$day} a las ".substr($booking->starts_at, 0, 5)." ({$payment}).";
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
        return new PushMessage('Nueva reserva', $this->body, ['type' => 'lesson_booked', 'route' => '/particulares/agenda']);
    }
}
