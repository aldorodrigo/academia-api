<?php

namespace App\Notifications;

use App\Models\EnrollmentRequest;
use App\Support\Push\PushMessage;

/**
 * Aviso a quienes aprueban: una familia pidió una inscripción desde la app.
 */
class EnrollmentRequested extends PushNotification
{
    public string $body;

    public function __construct(EnrollmentRequest $request)
    {
        $request->loadMissing(['user', 'group.program', 'organization']);
        $age = $request->birth_date->diffInYears($request->organization->today());

        $this->body = "{$request->user->name} quiere inscribir a {$request->fullName()} ({$this->years((int) $age)}) "
            ."en {$request->group->name} · {$request->group->program->name}.";
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage('Solicitud de inscripción', $this->body, ['type' => 'enrollment_request', 'route' => '/solicitudes']);
    }

    private function years(int $age): string
    {
        return $age === 1 ? '1 año' : "{$age} años";
    }
}
