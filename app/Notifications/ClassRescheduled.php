<?php

namespace App\Notifications;

use App\Models\ClassSession;
use App\Support\Push\PushMessage;
use Carbon\CarbonInterface;

/**
 * Aviso: una clase pasó a otro día u horario (o se canceló la reprogramación).
 */
class ClassRescheduled extends PushNotification
{
    public string $body;

    public string $route;

    public function __construct(ClassSession $session, ClassSession $makeup, bool $cancelled, bool $forInstructor)
    {
        $session->loadMissing(['group.program', 'organization']);
        $class = "La clase de {$session->group->program->name} {$session->group->name} del "
            .self::day($session->date).' a las '.substr($session->starts_at, 0, 5);
        $makeupTime = 'a las '.substr($makeup->starts_at, 0, 5);

        if ($cancelled) {
            $this->body = "{$class} ya no se recupera el ".self::day($makeup->date)." {$makeupTime}.";
            $this->route = $forInstructor ? "/clases/{$session->id}" : '/inicio';

            return;
        }

        $venue = $makeup->venue ? " ({$makeup->venue->name})" : '';
        $reason = filled($session->suspension_reason) ? " por {$session->suspension_reason}" : '';
        $when = $makeup->date->isSameDay($session->date) ? $makeupTime : 'al '.self::day($makeup->date)." {$makeupTime}";
        $this->body = "{$class}{$reason} pasa {$when}{$venue}.";
        $this->route = $forInstructor ? "/clases/{$makeup->id}" : '/inicio';
    }

    /**
     * "lunes 28/09".
     */
    private static function day(CarbonInterface $date): string
    {
        $weekdays = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

        return $weekdays[$date->dayOfWeekIso].' '.$date->format('d/m');
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage('Cambio de clase', $this->body, ['type' => 'class_rescheduled', 'route' => $this->route]);
    }
}
