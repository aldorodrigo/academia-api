<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\MedicalRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ficha médica: acceso restringido (ver StudentPolicy::viewMedical).
 */
#[Fillable(['organization_id', 'student_id', 'blood_type', 'allergies', 'conditions', 'medications', 'emergency_contact_name', 'emergency_contact_phone', 'fit_until'])]
class MedicalRecord extends Model
{
    /** @use HasFactory<MedicalRecordFactory> */
    use BelongsToOrganization, HasFactory;

    protected static function booted(): void
    {
        static::creating(function (MedicalRecord $record): void {
            $record->organization_id ??= $record->student?->organization_id;
        });
    }

    protected function casts(): array
    {
        return [
            'allergies' => 'encrypted',
            'conditions' => 'encrypted',
            'medications' => 'encrypted',
            'fit_until' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function isFitExpired(): bool
    {
        return $this->fit_until !== null && $this->fit_until->lt($this->student->organization->today());
    }
}
