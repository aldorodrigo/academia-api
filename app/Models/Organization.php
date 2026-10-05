<?php

namespace App\Models;

use App\Actions\Organizations\EnsureExpenseCategories;
use App\Actions\Organizations\EnsureFeeConcepts;
use App\Actions\Organizations\EnsureMoneyAccounts;
use App\Actions\Organizations\EnsureOrganizationRoles;
use App\Enums\Feature;
use App\Enums\Gender;
use App\Enums\OrganizationType;
use App\Support\Vocabulary;
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

#[Fillable(['name', 'slug', 'type', 'country', 'currency', 'timezone', 'terminology', 'terminology_feminine', 'features', 'billing', 'class_reminder_hours', 'instructor_reminder_hours', 'suspended_at', 'suspension_reason', 'self_service', 'onboarding_skipped', 'onboarding_dismissed_at', 'onboarding_completed_at'])]
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
        // Cancha, sala o aula de un lugar.
        'space' => 'Cancha',
    ];

    /** Palabras que nombran personas: tienen forma femenina y masculina. */
    public const PERSON_TERMS = ['student', 'instructor', 'guardian'];

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
        'class_reminder_hours' => 3,
        'instructor_reminder_hours' => 2,
    ];

    protected static function booted(): void
    {
        static::created(function (Organization $organization): void {
            app(EnsureOrganizationRoles::class)->handle($organization);
            app(EnsureFeeConcepts::class)->handle($organization);
            app(EnsureMoneyAccounts::class)->handle($organization);
            app(EnsureExpenseCategories::class)->handle($organization);
        });
    }

    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'terminology' => 'array',
            'terminology_feminine' => 'array',
            'terminology_confirmed_at' => 'datetime',
            'features' => 'array',
            'billing' => 'array',
            'class_reminder_hours' => 'integer',
            'instructor_reminder_hours' => 'integer',
            'suspended_at' => 'datetime',
            'self_service' => 'boolean',
            'onboarding_skipped' => 'array',
            'onboarding_dismissed_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
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
     * @return HasMany<Supplier, $this>
     */
    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    /**
     * @return HasMany<ExpenseCategory, $this>
     */
    public function expenseCategories(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class);
    }

    /**
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * @return HasMany<RecurringExpense, $this>
     */
    public function recurringExpenses(): HasMany
    {
        return $this->hasMany(RecurringExpense::class);
    }

    /**
     * @return HasMany<Transfer, $this>
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class);
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

    /**
     * La palabra del vocabulario (term('group') → "Categoría"). Con $gender, la forma para una persona
     * concreta de las palabras de persona: term('instructor', Gender::Female) → "Técnica".
     */
    public function term(string $key, ?Gender $gender = null): string
    {
        $word = $this->terminology[$key] ?? self::DEFAULT_TERMINOLOGY[$key] ?? $key;

        return $gender !== null && in_array($key, self::PERSON_TERMS, true)
            ? Vocabulary::forPerson($word, $gender, $this->terminology_feminine[$key] ?? null)
            : $word;
    }

    /**
     * Plural para un grupo de personas: femenino solo si son todas mujeres (Vocabulary::forPeople).
     *
     * @param  iterable<?Gender>  $genders
     */
    public function termForPeople(string $key, iterable $genders): string
    {
        return Vocabulary::forPeople($this->term($key), $genders, $this->terminology_feminine[$key] ?? null);
    }

    /**
     * Qué es la organización ("club", "academia", "escuela", "comisión"), para "del club" / "de la academia".
     */
    public function typeNoun(): string
    {
        return ($this->type ?? OrganizationType::Club)->noun();
    }

    /**
     * Las palabras con su plural, género, artículo y (las de persona) sus formas femenina y masculina,
     * más la de la organización ("organization": club, academia…). La app concuerda con esto.
     *
     * @return array<string, array<string, string>>
     */
    public function vocabulary(): array
    {
        return collect(array_keys(self::DEFAULT_TERMINOLOGY))
            ->mapWithKeys(fn (string $key) => [$key => Vocabulary::describe(
                $this->term($key),
                person: in_array($key, self::PERSON_TERMS, true),
                feminine: $this->terminology_feminine[$key] ?? null,
            )])
            ->put('organization', Vocabulary::describe($this->typeNoun()))
            ->all();
    }
}
