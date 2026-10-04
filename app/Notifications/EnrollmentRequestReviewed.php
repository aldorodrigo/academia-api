<?php

namespace App\Notifications;

use App\Enums\EnrollmentRequestStatus;
use App\Models\EnrollmentRequest;
use App\Support\Push\PushMessage;

/**
 * Aviso al tutor: su solicitud se aprobó (ya ve al hijo, sus clases y sus cuotas) o no (con el motivo).
 */
class EnrollmentRequestReviewed extends PushNotification
{
    public string $title;

    public string $body;

    public string $route;

    public bool $approved;

    public function __construct(EnrollmentRequest $request)
    {
        $this->approved = $request->status === EnrollmentRequestStatus::Approved;
        $place = "{$request->group->name} · {$request->group->program->name} ({$request->season->name})";

        [$this->title, $this->body, $this->route] = $this->approved
            ? ['Inscripción aprobada', "Aprobamos la inscripción de {$request->first_name} en {$place}. Ya ves sus clases y sus cuotas en la app.", "/hijos/{$request->student_id}"]
            : ['Inscripción no aprobada', "El club no aprobó la inscripción de {$request->first_name}: {$request->rejection_reason}", '/hijos'];
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage($this->title, $this->body, ['type' => 'enrollment_request_reviewed', 'route' => $this->route]);
    }

    protected function mailPose(): ?string
    {
        return $this->approved ? 'salta' : null;
    }
}
