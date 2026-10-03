<?php

namespace App\Models;

use App\Actions\Auth\SendVerificationCode;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Support\Phone;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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

#[Fillable(['name', 'email', 'phone', 'password', 'terms_accepted_at', 'terms_version'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasTenants, MustVerifyEmail
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
            'phone_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            $user->email = filled($user->email) ? mb_strtolower(trim($user->email)) : null;
            $user->phone = filled($user->phone) ? (Phone::normalize($user->phone) ?? trim($user->phone)) : null;
        });
    }

    /**
     * La cuenta se busca por celular o por correo (lo que se ingresa para entrar).
     */
    public static function findByLogin(string $login): ?self
    {
        $login = trim($login);

        if (Phone::looksLikeEmail($login)) {
            return static::query()->where('email', mb_strtolower($login))->first();
        }

        $phone = Phone::normalize($login);

        return $phone === null ? null : static::query()->where('phone', $phone)->first();
    }

    /**
     * Confirmó el celular o el correo con el código (o entró por una invitación).
     */
    public function isVerified(): bool
    {
        return $this->phone_verified_at !== null || $this->email_verified_at !== null;
    }

    /**
     * Para Filament (`emailVerification()`) la "verificación" es la de la cuenta: celular o correo.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->isVerified();
    }

    /**
     * Cuentas que todavía no ingresaron el código: no ocupan el número ni el correo.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull('phone_verified_at')->whereNull('email_verified_at')->whereDoesntHave('memberships');
    }

    /**
     * El código se manda por WhatsApp (o por correo), no con un link.
     */
    public function sendEmailVerificationNotification(): void
    {
        app(SendVerificationCode::class)->handle($this);
    }

    /**
     * Celular o correo, para mostrar.
     */
    public function contact(): string
    {
        return Phone::display($this->phone) ?? (string) $this->email;
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
     * Tiene el rol vigente en la organización (respeta mandatos).
     */
    public function hasCurrentRole(Organization $organization, OrganizationRole $role): bool
    {
        return $this->currentRoleAssignments($organization)
            ->contains(fn (RoleAssignment $assignment) => $assignment->role->name === $role->value);
    }

    /**
     * Administrador de la organización (rol admin vigente).
     */
    public function isOrganizationAdmin(Organization $organization): bool
    {
        return $this->hasCurrentRole($organization, OrganizationRole::Admin);
    }

    /**
     * Sus fichas de tutor (una por organización).
     *
     * @return HasMany<Guardian, $this>
     */
    public function guardians(): HasMany
    {
        return $this->hasMany(Guardian::class);
    }

    /**
     * Dispositivos registrados para push.
     *
     * @return HasMany<DeviceToken, $this>
     */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /**
     * Tokens de FCM para el canal de push.
     *
     * @return list<string>
     */
    public function routeNotificationForPush(): array
    {
        return $this->deviceTokens()->pluck('token')->all();
    }

    /**
     * Grupos que dirige como instructor.
     *
     * @return BelongsToMany<Group, $this>
     */
    public function instructedGroups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_instructor')->withTimestamps();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            // Panel de la plataforma: solo super admins.
            'platform' => (bool) $this->is_super_admin,
            // Una cuenta recién creada (sin ninguna membresía) entra para verificar su cuenta y crear su club.
            default => $this->is_super_admin || $this->activeOrganizations()->exists() || $this->memberships()->doesntExist(),
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
