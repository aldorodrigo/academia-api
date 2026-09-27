<?php

namespace App\Actions\Attendance;

use App\Enums\GuardianResponse;
use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * "¿Lo llevás?": el tutor avisa si el alumno va a la clase. "No va" deja al
 * alumno justificado al tomar asistencia (el técnico lo puede cambiar).
 */
class RespondToClass
{
    public function handle(ClassSession $session, Student $student, bool $going, User $user): Attendance
    {
        if ($session->isSuspended()) {
            throw ValidationException::withMessages(['going' => 'La clase está suspendida.']);
        }

        if ($session->hasStarted(CarbonImmutable::now())) {
            throw ValidationException::withMessages(['going' => 'La clase ya empezó.']);
        }

        if (! $session->students()->contains('id', $student->id)) {
            throw ValidationException::withMessages(['going' => 'El alumno no es de esta clase.']);
        }

        return Attendance::query()->updateOrCreate(
            ['class_session_id' => $session->id, 'student_id' => $student->id],
            [
                'organization_id' => $session->organization_id,
                'guardian_response' => $going ? GuardianResponse::Going : GuardianResponse::NotGoing,
                'responded_by' => $user->id,
                'responded_at' => now(),
            ],
        );
    }
}
