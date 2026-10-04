<?php

namespace App\Models;

use App\Enums\EnrollmentRequestStatus;
use App\Enums\GuardianRelationship;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Inscripción que pide un tutor desde la app (business-logic.md §3, "Inscripción desde la app"). El chico no es
 * alumno hasta que alguien con permiso la aprueba (`ReviewEnrollmentRequest`, que usa `RegisterStudent`).
 * La ficha médica viaja cifrada y no la ve quien aprueba: pasa a la ficha del alumno.
 */
#[Fillable(['organization_id', 'user_id', 'first_name', 'last_name', 'document', 'birth_date', 'relationship', 'season_id', 'group_id', 'notes', 'medical', 'status', 'reviewed_by', 'reviewed_at', 'rejection_reason', 'student_id'])]
#[Hidden(['medical'])]
class EnrollmentRequest extends Model
{
    use BelongsToOrganization, LogsActivity;

    protected $attributes = ['status' => 'pendiente', 'relationship' => 'tutor'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'relationship' => GuardianRelationship::class,
            'medical' => 'encrypted:array',
            'status' => EnrollmentRequestStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'group_id', 'rejection_reason', 'student_id'])
            ->logOnlyDirty()
            ->useLogName('enrollments');
    }

    public function isPending(): bool
    {
        return $this->status === EnrollmentRequestStatus::Pending;
    }

    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * El mismo chico ya cargado en el club (por documento, o nombre + apellido + nacimiento).
     */
    public function existingStudent(): ?Student
    {
        return Student::findExisting($this->document, $this->first_name, $this->last_name, $this->birth_date);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', EnrollmentRequestStatus::Pending);
    }

    /**
     * Quien la pidió.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Season, $this>
     */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * El alumno dado de alta al aprobarla.
     *
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
