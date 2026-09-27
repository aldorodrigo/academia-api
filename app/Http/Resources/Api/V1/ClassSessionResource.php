<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Clase para la app. Con counts para el técnico; detailed agrega los alumnos.
 *
 * @mixin ClassSession
 */
class ClassSessionResource extends JsonResource
{
    private bool $detailed = false;

    private bool $withCounts = true;

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    public function withoutCounts(): static
    {
        $this->withCounts = false;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $group = $this->group;

        $data = [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'starts_at' => Schedule::time($this->starts_at),
            'ends_at' => Schedule::time($this->ends_at),
            'venue' => $this->venue ? ['name' => $this->venue->name] : null,
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'program' => ['id' => $group->program->id, 'name' => $group->program->name],
            ],
            'status' => $this->status->value,
            'suspension_reason' => $this->suspension_reason,
            'charge_waived' => (bool) $this->charge_waived,
            'is_makeup' => (bool) $this->is_makeup,
            'rescheduled_to' => self::slot($this->rescheduledTo),
            'rescheduled_from' => self::slot($this->rescheduledFrom),
            'attendance_taken' => $this->isAttendanceTaken(),
        ];

        if ($this->withCounts) {
            $data['counts'] = $this->counts();
        }

        if (! $this->detailed) {
            return $data;
        }

        $attendances = $this->attendances()->get()->keyBy('student_id');

        return [
            ...$data,
            'editable' => $this->isEditable(),
            'can_waive_charge' => $this->canWaiveCharge(),
            'students' => $this->students()->map(function (Student $student) use ($attendances) {
                /** @var Attendance|null $attendance */
                $attendance = $attendances->get($student->id);

                return [
                    'id' => $student->id,
                    'full_name' => $student->full_name,
                    'photo_url' => $student->photoUrl(),
                    'status' => $attendance?->status?->value,
                    'guardian_response' => $attendance?->guardian_response?->value,
                    'note' => $attendance?->note,
                ];
            })->values(),
        ];
    }

    /**
     * La otra clase de una reprogramación.
     *
     * @return array<string, mixed>|null
     */
    private static function slot(?ClassSession $session): ?array
    {
        return $session === null ? null : [
            'id' => $session->id,
            'date' => $session->date->toDateString(),
            'starts_at' => Schedule::time($session->starts_at),
            'ends_at' => Schedule::time($session->ends_at),
            'venue' => $session->venue ? ['name' => $session->venue->name] : null,
        ];
    }
}
