<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Support\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Push al profesor: las clases particulares del día ("Hoy tenés 3 clases: 16:00 Mateo, …").
 */
class TeacherDayReminder extends Notification implements ShouldQueue
{
    use Queueable;

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

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['push'];
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage('Clases particulares', $this->body, ['type' => 'lesson_today', 'route' => '/particulares/agenda']);
    }
}
