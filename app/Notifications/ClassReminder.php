<?php

namespace App\Notifications;

use App\Models\ClassSession;
use App\Models\Student;
use App\Support\Push\PushMessage;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * Aviso de día de clase al tutor: "Hoy Mateo tiene Fútbol a las 17:00 (Cancha 1). ¿Lo llevás?"
 * con los botones "Sí, va" / "No va" (links firmados que vencen al empezar la clase).
 */
class ClassReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public string $body;

    /** @var array<string, string> */
    public array $data;

    /**
     * @param  Collection<int, Student>|Student  $students  alumnos del usuario en esa clase
     */
    public function __construct(ClassSession $session, Collection|Student $students, CarbonImmutable $today, int $userId)
    {
        $session->loadMissing(['group.program', 'venue', 'organization']);
        $students = $students instanceof Student ? collect([$students]) : $students->values();
        $names = self::names($students->pluck('first_name')->all());
        $verb = $students->count() > 1 ? 'tienen' : 'tiene';
        $day = ucfirst(self::dayLabel($session->date, $today));
        $venue = $session->venue ? " ({$session->venue->name})" : '';

        $this->body = "{$day} {$names} {$verb} {$session->group->program->name} a las "
            .substr($session->starts_at, 0, 5)."{$venue}. ".($students->count() > 1 ? '¿Los llevás?' : '¿Lo llevás?');

        $link = fn (bool $going) => URL::temporarySignedRoute('api.v1.class-responses', $session->startsAt(), [
            'class' => $session->id,
            'user' => $userId,
            'students' => $students->pluck('id')->implode(','),
            'going' => $going ? 1 : 0,
        ]);

        $this->data = [
            'type' => 'class_reminder',
            'route' => '/inicio',
            'class_id' => (string) $session->id,
            'student_ids' => $students->pluck('id')->implode(','),
            'going_url' => $link(true),
            'not_going_url' => $link(false),
        ];
    }

    /**
     * "Mateo", "Mateo y Sofía", "Ana, Mateo y Sofía".
     *
     * @param  list<string>  $names
     */
    public static function names(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names)." y {$last}";
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
        return new PushMessage('Día de clase', $this->body, $this->data, withActions: true, category: 'CLASS_REMINDER');
    }
}
