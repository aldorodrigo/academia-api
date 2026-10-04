<?php

namespace App\Notifications;

use App\Models\Enrollment;
use App\Models\User;
use App\Support\Push\PushMessage;

/**
 * Aviso a quienes pueden dar de baja: el técnico avisó que un alumno dejó de venir. Se decide en el
 * panel (Inscripciones → "Avisó el técnico": "Dar de baja" o "Sigue viniendo").
 */
class DropoutReported extends PushNotification
{
    public string $body;

    public string $panelUrl;

    public function __construct(Enrollment $enrollment, User $by)
    {
        $enrollment->loadMissing(['student', 'group', 'organization']);
        $note = filled($enrollment->dropout_note) ? ": «{$enrollment->dropout_note}»" : '.';

        $this->body = "{$by->name} avisó que {$enrollment->student->full_name} ({$enrollment->group->name}) dejó de venir{$note}"
            .' Decidí en el panel si le das la baja.';
        $this->panelUrl = url('/admin/'.$enrollment->organization->slug.'/inscripciones?tableFilters[dropout][isActive]=true');
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage('Dejó de venir', $this->body, ['type' => 'dropout_reported', 'route' => '/inicio']);
    }

    protected function mailActions(PushMessage $push): array
    {
        return [['label' => 'Ver en el panel', 'url' => $this->panelUrl]];
    }
}
