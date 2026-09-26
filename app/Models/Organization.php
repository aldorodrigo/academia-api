<?php

namespace App\Models;

use App\Actions\Organizations\EnsureOrganizationRoles;
use App\Enums\Feature;
use App\Enums\OrganizationType;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'type', 'country', 'currency', 'timezone', 'terminology', 'features'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Etiquetas por defecto; cada organización puede sobrescribirlas.
     *
     * @var array<string, string>
     */
    public const DEFAULT_TERMINOLOGY = [
        'program' => 'Disciplina',
        'group' => 'Categoría',
        'student' => 'Jugador',
        'instructor' => 'Técnico',
        'guardian' => 'Tutor',
    ];

    /**
     * Mismos valores por defecto que la migración, para que el modelo recién
     * creado los tenga sin volver a leerlo de la base.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'club',
        'country' => 'PY',
        'currency' => 'PYG',
        'timezone' => 'America/Asuncion',
    ];

    protected static function booted(): void
    {
        static::created(fn (Organization $organization) => app(EnsureOrganizationRoles::class)->handle($organization));
    }

    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'terminology' => 'array',
            'features' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')
            ->withPivot('status')
            ->withTimestamps();
    }

    /**
     * Roles de la organización (lo usa el recurso de roles de Shield en el panel).
     *
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * @return HasMany<Season, $this>
     */
    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class);
    }

    /**
     * @return HasMany<RoleAssignment, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * Hoy en la zona horaria de la organización (los mandatos vencen por fecha local).
     */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone)->startOfDay();
    }

    public function hasFeature(Feature $feature): bool
    {
        return in_array($feature->value, $this->features ?? [], true);
    }

    public function term(string $key): string
    {
        return $this->terminology[$key] ?? self::DEFAULT_TERMINOLOGY[$key] ?? $key;
    }
}
