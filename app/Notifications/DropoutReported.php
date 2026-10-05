<?php

namespace App\Notifications;

use App\Models\Enrollment;
use App\Models\User;
use App\Support\Push\PushMessage;
use App\Support\Vocabulary;

/**
 * Aviso a quienes pueden dar de baja: el técnico avisó que un alumno dejó de venir o el tutor que
 * deja el club. Se decide en la app (`/bajas`) o en el panel (Inscripciones → "Con aviso de baja").
 */
class DropoutReported extends PushNotification
{
    public string $body;

    public string $panelUrl;

    public function __construct(Enrollment $enrollment, User $by)
    {
        $enrollment->loadMissing(['student', 'group', 'organization']);
        $note = filled($enrollment->dropout_note) ? ": «{$enrollment->dropout_note}»" : '.';
        $what = $enrollment->dropout_source === 'guardian'
            ? "{$by->name} (familia) avisó que {$enrollment->student->full_name} deja ".Vocabulary::the($enrollment->organization->typeNoun())
            : "{$by->name} avisó que {$enrollment->student->full_name} ({$enrollment->group->name}) dejó de venir";

        $this->body = $what.$note.' Decidí si le das la baja.';
        $this->panelUrl = url('/admin/'.$enrollment->organization->slug.'/inscripciones?tableFilters[dropout][isActive]=true');
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage('Aviso de baja', $this->body, ['type' => 'dropout_reported', 'route' => '/bajas']);
    }

    protected function mailActions(PushMessage $push): array
    {
        return [
            ['label' => 'Ver en Tuku', 'url' => rtrim(config('app.frontend_url'), '/').'/bajas'],
            ['label' => 'Ver en el panel', 'url' => $this->panelUrl, 'color' => 'secondary'],
        ];
    }
}
