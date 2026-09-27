<?php

namespace App\Models;

use App\Actions\Organizations\EnsureFeeConcepts;
use App\Actions\Organizations\EnsureMoneyAccounts;
use App\Actions\Organizations\EnsureOrganizationRoles;
use App\Enums\Feature;
use App\Enums\OrganizationType;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'type', 'country', 'currency', 'timezone', 'terminology', 'features', 'billing', 'suspended_at', 'suspension_reason'])]
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
     * Configuración de cobros por defecto (ver billing()).
     *
     * @var array<string, mixed>
     */
    public const DEFAULT_BILLING = [
        // Día del mes en que vence la cuota y días de gracia antes de figurar "vencido".
        'due_day' => 10,
        'grace_days' => 0,
        // Orden en que se aplican los descuentos (cada uno sobre lo que queda).
        'discount_order' => ['beca', 'hermanos', 'convenio', 'otro'],
        // Recargo por mora: se configura ahora y se aplica desde el registro de pagos (Sprint 4).
        'late_fee' => ['enabled' => false, 'type' => 'percent', 'value' => 0, 'frequency' => 'once', 'cap' => null],
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
        static::created(function (Organization $organization): void {
            app(EnsureOrganizationRoles::class)->handle($organization);
            app(EnsureFeeConcepts::class)->handle($organization);
            app(EnsureMoneyAccounts::class)->handle($organization);
        });
    }

    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'terminology' => 'array',
            'features' => 'array',
            'billing' => 'array',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * Organizaciones no suspendidas.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('suspended_at');
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Bloquea el acceso de todos sus miembros (salvo super admins); los datos se conservan.
     */
    public function suspend(string $reason): void
    {
        $this->update(['suspended_at' => now(), 'suspension_reason' => $reason]);

        activity('platform')->performedOn($this)
            ->withProperties(['reason' => $reason])
            ->log('Organización suspendida');
    }

    public function reactivate(): void
    {
        $this->update(['suspended_at' => null, 'suspension_reason' => null]);

        activity('platform')->performedOn($this)->log('Organización reactivada');
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
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * @return HasMany<RoleAssignment, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * @return HasMany<Venue, $this>
     */
    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class);
    }

    /**
     * @return HasMany<Program, $this>
     */
    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }

    /**
     * @return HasMany<Group, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    /**
     * @return HasMany<Family, $this>
     */
    public function families(): HasMany
    {
        return $this->hasMany(Family::class);
    }

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
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * @return HasMany<FeeConcept, $this>
     */
    public function feeConcepts(): HasMany
    {
        return $this->hasMany(FeeConcept::class);
    }

    /**
     * @return HasMany<Tariff, $this>
     */
    public function tariffs(): HasMany
    {
        return $this->hasMany(Tariff::class);
    }

    /**
     * @return HasMany<Charge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /**
     * @return HasMany<DiscountRule, $this>
     */
    public function discountRules(): HasMany
    {
        return $this->hasMany(DiscountRule::class);
    }

    /**
     * @return HasMany<Scholarship, $this>
     */
    public function scholarships(): HasMany
    {
        return $this->hasMany(Scholarship::class);
    }

    /**
     * @return HasMany<MoneyAccount, $this>
     */
    public function moneyAccounts(): HasMany
    {
        return $this->hasMany(MoneyAccount::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * Hoy en la zona horaria de la organización (los mandatos vencen por fecha local).
     */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone)->startOfDay();
    }

    /**
     * Configuración de cobros combinada con los valores por defecto.
     */
    public function billing(?string $key = null): mixed
    {
        $billing = array_replace(self::DEFAULT_BILLING, $this->billing ?? []);

        return $key === null ? $billing : ($billing[$key] ?? null);
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
