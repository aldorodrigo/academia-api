<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\FamilyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agrupa alumnos y tutores. Base del estado de cuenta consolidado (Sprint 3).
 */
#[Fillable(['organization_id', 'name'])]
class Family extends Model
{
    /** @use HasFactory<FamilyFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * @return HasMany<Student, $this>
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /**
     * @return HasMany<Guardian, $this>
     */
    public function guardians(): HasMany
    {
        return $this->hasMany(Guardian::class);
    }

    /**
     * Familia automática: la del alumno, la de alguno de sus tutores o una nueva.
     * Los hermanos que comparten un tutor quedan juntos.
     */
    public static function syncFor(Student $student): ?self
    {
        $guardians = $student->guardians()->get();

        if ($student->family_id === null && $guardians->isEmpty()) {
            return null;
        }

        $familyId = $student->family_id
            ?? $guardians->pluck('family_id')->filter()->first()
            ?? static::query()->create([
                'organization_id' => $student->organization_id,
                'name' => "Familia {$student->last_name}",
            ])->id;

        if ($student->family_id !== $familyId) {
            $student->update(['family_id' => $familyId]);
        }

        $guardians->whereNull('family_id')->each->update(['family_id' => $familyId]);

        return static::query()->find($familyId);
    }
}
