<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Support\Push\PushMessage;
use Illuminate\Support\Collection;

/**
 * Aviso al profesor: las clases particulares del día ("Hoy tenés 3 clases: 16:00 Mateo, …").
 */
class TeacherDayReminder extends PushNotification
{
    public string $body;

    /**
     * @param  Collection<int, Booking>  $bookings  del mismo día, por hora
     */
    public function __construct(Collection $bookings)
    {
        $first = $bookings->first();
        $first->loadMissing('organization');
        $day = ucfirst(LessonReminder::dayLabel($first));
        $count = $bookings->count();
        $list = $bookings->map(fn (Booking $booking) => substr($booking->starts_at, 0, 5).' '.$booking->student->first_name)->join(', ');

        $this->body = "{$day} tenés ".($count === 1 ? '1 clase' : "{$count} clases").": {$list}.";
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage('Clases particulares', $this->body, ['type' => 'lesson_today', 'route' => '/particulares/agenda']);
    }
}
