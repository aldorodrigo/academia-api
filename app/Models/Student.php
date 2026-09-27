<?php

namespace App\Models;

use App\Enums\EnrollmentStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Alumno. El nombre visible sale de term('student').
 */
#[Fillable(['organization_id', 'family_id', 'user_id', 'first_name', 'last_name', 'document', 'birth_date', 'shirt_size', 'position', 'notes'])]
class Student extends Model implements HasMedia
{
    /** @use HasFactory<StudentFactory> */
    use BelongsToOrganization, HasFactory, InteractsWithMedia;

    public const ADULT_AGE = 18;

    protected function casts(): array
    {
        return ['birth_date' => 'date'];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photo')->singleFile();
    }

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->last_name}"));
    }

    /**
     * Alumnos a cargo del usuario: sus hijos (como tutor vinculado) o él mismo (alumno adulto).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inChargeOf(Builder $query, User $user): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('user_id', $user->id)
            ->orWhereHas('guardians', fn (Builder $guardians) => $guardians->where('guardians.user_id', $user->id)));
    }

    /**
     * El mismo chico ya cargado: por documento o por nombre + apellido + fecha de nacimiento.
     */
    public static function findExisting(?string $document, ?string $firstName, ?string $lastName, mixed $birthDate): ?self
    {
        $byDocument = filled($document) ? static::query()->where('document', trim($document))->first() : null;

        if ($byDocument !== null || blank($firstName) || blank($lastName) || blank($birthDate)) {
            return $byDocument;
        }

        return static::query()
            ->where('first_name', trim($firstName))
            ->where('last_name', trim($lastName))
            ->whereDate('birth_date', Carbon::parse($birthDate)->toDateString())
            ->first();
    }

    /**
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Guardian, $this>
     */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class)
            ->withPivot('relationship')
            ->withTimestamps();
    }

    /**
     * @return HasOne<MedicalRecord, $this>
     */
    public function medicalRecord(): HasOne
    {
        return $this->hasOne(MedicalRecord::class);
    }

    /**
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * Inscripciones de temporadas vigentes o próximas.
     *
     * @return HasMany<Enrollment, $this>
     */
    public function currentEnrollments(): HasMany
    {
        return $this->enrollments()->whereHas('season', fn (Builder $season) => $season->open());
    }

    /**
     * @return HasMany<Charge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    public function isAdult(): bool
    {
        return $this->birth_date !== null && $this->birth_date->age >= self::ADULT_AGE;
    }

    /**
     * Tiene una inscripción (no dada de baja) en una temporada vigente o próxima.
     */
    public function isActive(): bool
    {
        return $this->currentEnrollments()->where('status', '!=', EnrollmentStatus::Withdrawn)->exists();
    }

    public function photoUrl(): ?string
    {
        return $this->getFirstMediaUrl('photo') ?: null;
    }
}
