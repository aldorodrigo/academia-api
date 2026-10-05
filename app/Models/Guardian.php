<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Phone;
use Database\Factories\GuardianFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tutor (padre, madre…). Se vincula a un usuario al aceptar la invitación. No se borra: "Eliminar" lo archiva
 * (soft delete) y deja de aparecer en listas, fichas y avisos; el alta lo restaura si vuelve con el mismo dato.
 */
#[Fillable(['organization_id', 'family_id', 'user_id', 'first_name', 'last_name', 'document', 'email', 'phone'])]
class Guardian extends Model
{
    /** @use HasFactory<GuardianFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (Guardian $guardian): void {
            $guardian->email = filled($guardian->email) ? mb_strtolower(trim($guardian->email)) : null;
            // Formato internacional si es un número válido; si no, tal como se cargó.
            $guardian->phone = filled($guardian->phone) ? (Phone::normalize($guardian->phone) ?? trim($guardian->phone)) : null;
        });
    }

    /**
     * Celular para mostrar ("0981 123 456").
     *
     * @return Attribute<?string, never>
     */
    protected function phoneDisplay(): Attribute
    {
        return Attribute::get(fn () => Phone::display($this->phone) ?? $this->phone);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->last_name}"));
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
     * @return BelongsToMany<Student, $this>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class)
            ->withPivot('relationship')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * Su género: el que eligió en "Mi cuenta" o, si no, el que se deduce del parentesco con sus chicos
     * (Madre → femenino, Padre → masculino; Tutor/a u Otro no dicen nada). No se le pregunta.
     */
    public function gender(): ?Gender
    {
        if ($this->user?->gender !== null) {
            return $this->user->gender;
        }

        return $this->students()->withoutGlobalScopes()->get()
            ->map(fn (Student $student) => GuardianRelationship::parse($student->pivot->relationship)->gender())
            ->filter()
            ->first();
    }

    /**
     * Ya tiene cuenta en la app (aceptó la invitación).
     */
    public function hasAccount(): bool
    {
        return $this->user_id !== null;
    }
}
