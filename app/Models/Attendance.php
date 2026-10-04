<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\GuardianResponse;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un alumno en una clase: la marca del técnico y la respuesta del tutor.
 */
#[Fillable(['organization_id', 'class_session_id', 'student_id', 'status', 'note', 'marked_by', 'marked_at', 'guardian_response', 'responded_by', 'responded_at'])]
class Attendance extends Model
{
    use BelongsToOrganization;

    protected static function booted(): void
    {
        static::creating(function (Attendance $attendance): void {
            $attendance->organization_id ??= $attendance->classSession?->organization_id;
        });
    }

    protected function casts(): array
    {
        return [
            'status' => AttendanceStatus::class,
            'guardian_response' => GuardianResponse::class,
            'marked_at' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ClassSession, $this>
     */
    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Presentes, ausentes, justificados y % de presentes.
     *
     * @param  array<string, int>  $marks
     * @return array{present: int, absent: int, justified: int, rate: ?int}
     */
    public static function summary(array $marks): array
    {
        $present = $marks[AttendanceStatus::Present->value] ?? 0;
        $absent = $marks[AttendanceStatus::Absent->value] ?? 0;
        $justified = $marks[AttendanceStatus::Justified->value] ?? 0;
        $total = $present + $absent + $justified;

        return [
            'present' => $present,
            'absent' => $absent,
            'justified' => $justified,
            'rate' => $total === 0 ? null : (int) round($present * 100 / $total),
        ];
    }
}
