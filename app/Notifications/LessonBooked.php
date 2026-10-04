<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Support\Push\PushMessage;

/**
 * Aviso al profesor: le reservaron una clase.
 */
class LessonBooked extends PushNotification
{
    public string $body;

    public function __construct(Booking $booking)
    {
        $booking->loadMissing(['student', 'organization']);
        $day = LessonReminder::dayLabel($booking);
        $payment = $booking->usesPack() ? 'con el paquete' : 'clase suelta';

        $this->body = "{$booking->student->full_name}, {$day} a las ".substr($booking->starts_at, 0, 5)." ({$payment}).";
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage('Nueva reserva', $this->body, ['type' => 'lesson_booked', 'route' => '/particulares/agenda']);
    }

    protected function mailPose(): ?string
    {
        return 'salta';
    }
}
