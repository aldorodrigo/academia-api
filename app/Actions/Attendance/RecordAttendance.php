<?php

namespace App\Actions\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Guarda la asistencia de una clase de una vez (idempotente): una marca por alumno.
 */
class RecordAttendance
{
    /**
     * @param  list<array{student_id: int, status: string, note?: ?string}>  $marks
     */
    public function handle(ClassSession $session, array $marks, User $user, bool $fromPanel = false): void
    {
        if ($session->isOff()) {
            throw ValidationException::withMessages(['marks' => $session->isRescheduled() ? 'La clase se reprogramó: tomá la asistencia en la recuperación.' : 'La clase está suspendida.']);
        }

        if (! $fromPanel && ! $session->isEditable()) {
            throw ValidationException::withMessages(['marks' => 'Esta clase ya no se puede corregir desde la app.']);
        }

        $students = $session->students()->pluck('id')->flip();

        foreach ($marks as $mark) {
            if (! $students->has($mark['student_id'])) {
                throw ValidationException::withMessages(['marks' => 'Hay alumnos que no son de esta clase.']);
            }
        }

        DB::transaction(function () use ($session, $marks, $user) {
            $now = now();

            foreach ($marks as $mark) {
                Attendance::query()->updateOrCreate(
                    ['class_session_id' => $session->id, 'student_id' => $mark['student_id']],
                    [
                        'organization_id' => $session->organization_id,
                        'status' => AttendanceStatus::from($mark['status']),
                        'note' => filled($mark['note'] ?? null) ? trim($mark['note']) : null,
                        'marked_by' => $user->id,
                        'marked_at' => $now,
                    ],
                );
            }

            $session->update(['attendance_taken_at' => $now, 'attendance_taken_by' => $user->id]);
        });
    }
}
