<?php

namespace App\Notifications;

use App\Models\ClassSession;
use App\Support\Push\PushMessage;
use Carbon\CarbonImmutable;

/**
 * Aviso al técnico: "Hoy tenés clase con Sub-10 a las 17:00 (Cancha 1) · 15 van, 2 no van,
 * 4 sin responder", con el botón "Tomar asistencia".
 */
class InstructorClassReminder extends PushNotification
{
    public string $body;

    public int $classId;

    public function __construct(ClassSession $session, CarbonImmutable $today)
    {
        $session->loadMissing(['group.program', 'venue']);
        $counts = $session->counts();
        $day = ucfirst(ClassReminder::dayLabel($session->date, $today));
        $venue = $session->venue ? " ({$session->venue->label})" : '';
        $makeup = $session->is_makeup ? ' (recuperación)' : '';

        $this->classId = $session->id;
        $this->body = "{$day} tenés clase con {$session->group->name}{$makeup} a las ".substr($session->starts_at, 0, 5)."{$venue} · "
            .implode(', ', array_filter([
                "{$counts['going']} van",
                $counts['not_going'] > 0 ? "{$counts['not_going']} no van" : null,
                $counts['no_answer'] > 0 ? "{$counts['no_answer']} sin responder" : null,
            ]));
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage('Mis clases', $this->body, [
            'type' => 'class_today',
            'route' => "/clases/{$this->classId}",
            'class_id' => (string) $this->classId,
        ], withActions: true, category: 'CLASS_TODAY');
    }

    protected function mailActions(PushMessage $push): array
    {
        return [['label' => 'Tomar asistencia', 'url' => rtrim(config('app.frontend_url'), '/').$push->data['route']]];
    }
}
