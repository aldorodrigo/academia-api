<?php

namespace App\Models;

use App\Enums\EnrollmentStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inscripción = alumno + grupo + temporada. Cada una genera sus propios cargos (Sprint 3).
 */
#[Fillable(['organization_id', 'student_id', 'group_id', 'season_id', 'status', 'enrolled_on', 'ended_on', 'notes'])]
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use BelongsToOrganization, HasFactory;

    protected $attributes = ['status' => 'pendiente'];

    protected static function booted(): void
    {
        static::creating(function (Enrollment $enrollment): void {
            $enrollment->organization_id ??= $enrollment->student?->organization_id;
        });

        // Al pasar a baja queda registrada la fecha; al reactivarla se limpia.
        static::saving(function (Enrollment $enrollment): void {
            if ($enrollment->isDirty('status')) {
                $enrollment->ended_on = $enrollment->status === EnrollmentStatus::Withdrawn
                    ? ($enrollment->ended_on ?? now()->toDateString())
                    : null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => EnrollmentStatus::class,
            'enrolled_on' => 'date',
            'ended_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return BelongsTo<Season, $this>
     */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }
}
