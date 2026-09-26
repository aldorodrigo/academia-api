<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\GuardianFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tutor (padre, madre…). Se vincula a un usuario al aceptar la invitación.
 */
#[Fillable(['organization_id', 'family_id', 'user_id', 'first_name', 'last_name', 'document', 'email', 'phone'])]
class Guardian extends Model
{
    /** @use HasFactory<GuardianFactory> */
    use BelongsToOrganization, HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Guardian $guardian): void {
            $guardian->email = filled($guardian->email) ? mb_strtolower(trim($guardian->email)) : null;
        });
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
     * Ya tiene cuenta en la app (aceptó la invitación).
     */
    public function hasAccount(): bool
    {
        return $this->user_id !== null;
    }
}
