<?php

namespace App\Notifications;

use App\Models\ClassSession;
use App\Models\Student;
use App\Support\Push\PushMessage;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Aviso de día de clase: "Hoy Mateo tiene Fútbol a las 17:00 (Cancha 1). ¿Lo llevás?".
 */
class ClassReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public string $body;

    public function __construct(ClassSession $session, Student $student, CarbonImmutable $today)
    {
        $session->loadMissing(['group.program', 'venue']);
        $day = ucfirst(self::dayLabel($session->date, $today));
        $venue = $session->venue ? " ({$session->venue->name})" : '';

        $this->body = "{$day} {$student->first_name} tiene {$session->group->program->name} a las "
            .substr($session->starts_at, 0, 5)."{$venue}. ¿Lo llevás?";
    }

    /**
     * "hoy", "mañana" o "el 12/10".
     */
    public static function dayLabel(CarbonImmutable $date, CarbonImmutable $today): string
    {
        return match (true) {
            $date->isSameDay($today) => 'hoy',
            $date->isSameDay($today->addDay()) => 'mañana',
            default => 'el '.$date->format('j/n'),
        };
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
        return new PushMessage('Día de clase', $this->body, ['type' => 'class_reminder', 'route' => '/inicio']);
    }
}
