<?php

namespace App\Notifications;

use App\Models\ClassSession;
use App\Support\Push\PushMessage;

/**
 * Aviso a los tutores: se suspendió una clase del grupo.
 */
class ClassSuspended extends PushNotification
{
    public string $body;

    public function __construct(ClassSession $session)
    {
        $session->loadMissing(['group.program', 'organization']);
        $day = ClassReminder::dayLabel($session->date, $session->organization->today());
        $reason = filled($session->suspension_reason) ? " ({$session->suspension_reason})" : '';

        $this->body = "Se suspendió la clase de {$session->group->program->name} {$session->group->name} de {$day} a las "
            .substr($session->starts_at, 0, 5).$reason.'.';
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage('Clase suspendida', $this->body, ['type' => 'class_suspended', 'route' => '/inicio']);
    }

    protected function mailPose(): ?string
    {
        return 'descansa';
    }
}
