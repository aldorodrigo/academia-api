<?php

namespace App\Models;

use App\Enums\ScholarshipStatus;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\ScholarshipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Beca parcial (%) o total (100) sobre una inscripción. Se aplica solo aprobada
 * y dentro de su vigencia (la aprueba quien tiene el permiso Approve:Scholarship).
 */
#[Fillable(['organization_id', 'student_id', 'enrollment_id', 'percent', 'reason', 'valid_from', 'valid_to', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note'])]
class Scholarship extends Model
{
    /** @use HasFactory<ScholarshipFactory> */
    use BelongsToOrganization, HasFactory, LogsActivity;

    protected $attributes = ['status' => 'pendiente'];

    protected static function booted(): void
    {
        static::creating(function (Scholarship $scholarship): void {
            $scholarship->student_id ??= $scholarship->enrollment?->student_id;
            $scholarship->organization_id ??= $scholarship->enrollment?->organization_id;
        });
    }

    protected function casts(): array
    {
        return [
            'percent' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'status' => ScholarshipStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['percent', 'reason', 'valid_from', 'valid_to', 'status', 'decided_by', 'decision_note'])
            ->logOnlyDirty()
            ->useLogName('billing');
    }

    public function isTotal(): bool
    {
        return $this->percent >= 100;
    }

    public function appliesOn(CarbonInterface $date): bool
    {
        return $this->status === ScholarshipStatus::Approved
            && $this->valid_from->lte($date)
            && ($this->valid_to === null || $this->valid_to->gte($date));
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
