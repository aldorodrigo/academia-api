<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'memberships')
            ->withPivot('status')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Organization, $this>
     */
    public function activeOrganizations(): BelongsToMany
    {
        return $this->organizations()
            ->wherePivot('status', MembershipStatus::Active->value)
            ->whereNull('organizations.suspended_at');
    }

    public function belongsToOrganization(Organization $organization): bool
    {
        return $this->is_super_admin
            || $this->activeOrganizations()->whereKey($organization->getKey())->exists();
    }

    /**
     * @return HasMany<RoleAssignment, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * Asignaciones vigentes en la organización dada (con el rol cargado).
     *
     * @return Collection<int, RoleAssignment>
     */
    public function currentRoleAssignments(Organization $organization): Collection
    {
        return RoleAssignment::query()
            ->withoutGlobalScopes()
            ->with(['role', 'organization'])
            ->where('organization_id', $organization->id)
            ->where('user_id', $this->id)
            ->current($organization->today()->toDateString())
            ->orderBy('id')
            ->get();
    }

    /**
     * Administrador de la organización (rol admin vigente).
     */
    public function isOrganizationAdmin(Organization $organization): bool
    {
        return $this->currentRoleAssignments($organization)
            ->contains(fn (RoleAssignment $assignment) => $assignment->role->name === OrganizationRole::Admin->value);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            // Panel de la plataforma: solo super admins.
            'platform' => (bool) $this->is_super_admin,
            default => $this->is_super_admin || $this->activeOrganizations()->exists(),
        };
    }

    /**
     * @return Collection<int, Organization>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->is_super_admin
            ? Organization::query()->orderBy('name')->get()
            : $this->activeOrganizations()->orderBy('name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Organization && $this->belongsToOrganization($tenant);
    }
}
