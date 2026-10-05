<?php

use App\Actions\Billing\RegisterPayment;
use App\Console\Commands\SyncOrganizationRoles;
use App\Enums\OrganizationRole;
use App\Enums\PaymentMethod;
use App\Filament\Support\RoleFields;
use App\Filament\Widgets\Dashboard\Birthdays;
use App\Filament\Widgets\Dashboard\Cash;
use App\Filament\Widgets\Dashboard\Delinquents;
use App\Filament\Widgets\Dashboard\MonthBalance;
use App\Filament\Widgets\Dashboard\MonthCollection;
use App\Filament\Widgets\Dashboard\Pending;
use App\Filament\Widgets\Dashboard\QuickActions;
use App\Filament\Widgets\Dashboard\Students;
use App\Filament\Widgets\Dashboard\TodayClasses;
use App\Models\Charge;
use App\Models\Enrollment;
use App\Models\Family;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Season;
use App\Models\Student;
use App\Models\User;
use App\Support\Dashboard\Metrics;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-15 12:00:00');
    $this->club = Organization::factory()->create(['slug' => 'jakare', 'name' => 'Club Jakare']);
    app(CurrentOrganization::class)->set($this->club);

    $season = Season::factory()->for($this->club)->create(['name' => '2026', 'starts_on' => '2026-02-01', 'ends_on' => '2026-11-30']);
    $futbol = Program::factory()->for($this->club)->create(['name' => 'Fútbol']);
    $this->group = Group::factory()->for($futbol)->create(['name' => 'Sub-10', 'organization_id' => $this->club->id, 'capacity' => 2]);

    $this->benitez = Family::factory()->for($this->club)->create(['name' => 'Familia Benítez']);
    Guardian::factory()->for($this->club)->create(['first_name' => 'Ana', 'last_name' => 'Benítez', 'family_id' => $this->benitez->id, 'phone' => '+595981123456']);
    $rojas = Family::factory()->for($this->club)->create(['name' => 'Familia Rojas']);

    $this->mateo = Student::factory()->for($this->club)->create(['first_name' => 'Mateo', 'last_name' => 'Benítez', 'birth_date' => '2016-09-17', 'family_id' => $this->benitez->id]);
    $this->sofia = Student::factory()->for($this->club)->create(['first_name' => 'Sofía', 'last_name' => 'Benítez', 'birth_date' => '2018-03-02', 'family_id' => $this->benitez->id]);
    $this->lucia = Student::factory()->for($this->club)->create(['first_name' => 'Lucía', 'last_name' => 'Rojas', 'birth_date' => '2017-12-01', 'family_id' => $rojas->id]);
    foreach ([$this->mateo, $this->sofia, $this->lucia] as $student) {
        Enrollment::withoutSeasonCharges(fn () => Enrollment::factory()->create([
            'student_id' => $student->id, 'group_id' => $this->group->id, 'season_id' => $season->id,
            'enrolled_on' => $student->is($this->lucia) ? '2026-09-02' : '2026-02-01',
        ]));
    }

    // Agosto vencido; septiembre vencido el 10 (pagado en parte); una que vence el 20.
    Charge::factory()->create(['student_id' => $this->mateo->id, 'due_on' => '2026-08-10']);
    $september = Charge::factory()->create(['student_id' => $this->sofia->id, 'due_on' => '2026-09-10']);
    Charge::factory()->create(['student_id' => $this->lucia->id, 'due_on' => '2026-09-20']);
    Charge::factory()->create(['student_id' => $this->lucia->id, 'due_on' => '2026-09-10', 'voided_at' => now()]);

    app(RegisterPayment::class)->handle(
        $this->benitez, MoneyAccount::query()->where('name', 'Caja')->sole(), 50000, PaymentMethod::Cash,
        CarbonImmutable::parse('2026-09-03'), allocations: [$september->id => 50000],
    );
});

function dashboardUser(OrganizationRole $role): User
{
    $user = memberOf(test()->club);
    app(RoleAssigner::class)->assign(test()->club, $user, $role, endsOn: $role->isBoardPosition() ? CarbonImmutable::parse('2027-12-31') : null);
    test()->actingAs($user);
    filament()->setTenant(test()->club);

    return $user;
}

/**
 * @return list<string>
 */
function visibleCards(): array
{
    return collect([
        'botones' => QuickActions::class, 'hoy' => TodayClasses::class, 'cobranza' => MonthCollection::class,
        'morosos' => Delinquents::class, 'alumnos' => Students::class, 'caja' => Cash::class,
        'pendientes' => Pending::class, 'cumpleaños' => Birthdays::class, 'balance' => MonthBalance::class,
    ])->filter(fn (string $card) => $card::canView())->keys()->all();
}

describe('números', function () {
    it('cobranza del mes, vencido con días de gracia y lo que vence esta semana', function () {
        expect(Metrics::for($this->club)->collection())->toBe([
            'collected' => 50000,
            'due' => 300000,
            'due_pending' => 250000,
            'overdue' => 250000,
            'overdue_families' => 1,
        ])->and(Metrics::for($this->club)->dueThisWeek())->toBe(['amount' => 150000, 'count' => 1]);

        // Con 7 días de gracia, la del 10 de septiembre todavía no está vencida.
        $this->club->update(['billing' => ['grace_days' => 7]]);
        expect(Metrics::for($this->club->fresh())->collection()['overdue'])->toBe(150000);
    });

    it('morosos con su celular, alumnos con cupo y cumpleaños', function () {
        expect(Metrics::for($this->club)->topDelinquents())->toBe([[
            'family_id' => $this->benitez->id,
            'family' => 'Familia Benítez',
            'amount' => 250000,
            'oldest_due_on' => '2026-08-10',
            'phone' => '+595981123456',
        ]]);

        $students = Metrics::for($this->club)->students();
        expect($students)->toMatchArray(['active' => 3, 'new' => 1, 'withdrawn' => 0, 'full' => 1])
            ->and($students['groups'][0])->toMatchArray(['name' => 'Sub-10', 'count' => 3, 'capacity' => 2]);

        expect(collect(Metrics::for($this->club)->birthdays())->pluck('name')->all())->toBe(['Mateo Benítez'])
            ->and(Metrics::for($this->club)->birthdays()[0]['age'])->toBe(10);
    });

    it('las tarjetas se dibujan con los datos', function () {
        dashboardUser(OrganizationRole::Admin);

        Livewire::test(MonthCollection::class)->assertSee('Cobranza de septiembre')->assertSee('₲ 250.000')->assertSee('1 familia');
        Livewire::test(Delinquents::class)->assertSee('Familia Benítez')->assertSee('Desde el 10/08')->assertSee('wa.me/595981123456', false);
        Livewire::test(Students::class)->assertSee('3 activos')->assertSee('Lleno');
        Livewire::test(Birthdays::class)->assertSee('Mateo Benítez')->assertSee('10 años');
        Livewire::test(QuickActions::class)->assertSee('Nuevo jugador')->assertSee('Registrar pago')->assertSee('action=register', false);
    });

    it('"Hoy": las clases del día con su estado', function () {
        Schedule::query()->create(['group_id' => $this->group->id, 'weekday' => 2, 'starts_at' => '17:00', 'ends_at' => '18:30']);
        dashboardUser(OrganizationRole::Admin);

        Livewire::test(TodayClasses::class)->assertSee('17:00–18:30')->assertSee('Sub-10')->assertSee('3 inscriptos');

        $this->travelTo('2026-09-15 23:00:00'); // 20:00 en Asunción
        Livewire::test(TodayClasses::class)->assertSee('Falta la asistencia')->assertSee('1 sin asistencia');
    });
});

describe('según el rol', function () {
    it('el administrador ve todo', function () {
        dashboardUser(OrganizationRole::Admin);

        expect(visibleCards())->toBe(['botones', 'hoy', 'cobranza', 'morosos', 'alumnos', 'caja', 'pendientes', 'cumpleaños', 'balance']);
    });

    it('el tesorero ve el dinero; el secretario, los alumnos; el vocal, el balance', function () {
        dashboardUser(OrganizationRole::Treasurer);
        expect(visibleCards())->toBe(['botones', 'hoy', 'cobranza', 'morosos', 'alumnos', 'caja', 'balance'])
            ->and(collect(QuickActions::buttons())->pluck('label')->all())->toBe(['Registrar pago', 'Registrar gasto', 'Nuevo cargo', 'Inscripciones', 'Informes']);

        dashboardUser(OrganizationRole::Secretary);
        expect(visibleCards())->toBe(['botones', 'hoy', 'alumnos', 'pendientes', 'cumpleaños'])
            ->and(collect(QuickActions::buttons())->pluck('label')->all())->toBe(['Nuevo jugador', 'Invitar', 'Inscripciones']);

        dashboardUser(OrganizationRole::Member);
        expect(visibleCards())->toBe(['botones', 'balance'])
            ->and(collect(QuickActions::buttons())->pluck('label')->all())->toBe(['Informes']);
    });

    it('el técnico solo ve sus clases de hoy', function () {
        $coach = dashboardUser(OrganizationRole::Instructor);
        expect(visibleCards())->toBe([]);

        $coach->instructedGroups()->attach($this->group);
        expect(visibleCards())->toBe(['hoy']);
    });
});

describe('permisos por defecto', function () {
    it('cada rol nace con los permisos de su función', function () {
        $role = fn (OrganizationRole $base) => Role::query()->where('organization_id', $this->club->id)->where('name', $base->value)->sole();

        expect($role(OrganizationRole::Treasurer)->hasPermissionTo('Create:Payment'))->toBeTrue()
            ->and($role(OrganizationRole::Treasurer)->hasPermissionTo('Create:Student'))->toBeFalse()
            ->and($role(OrganizationRole::Secretary)->hasPermissionTo('Create:Student'))->toBeTrue()
            ->and($role(OrganizationRole::Secretary)->hasPermissionTo('ViewAny:Payment'))->toBeFalse()
            ->and($role(OrganizationRole::Member)->permissions->pluck('name')->all())->toBe(['View:Reports'])
            ->and($role(OrganizationRole::Secretary)->hasPermissionTo('Manage:EnrollmentRequests'))->toBeTrue()
            ->and($role(OrganizationRole::Instructor)->permissions->pluck('name')->all())->toBe(['Confirm:GroupEnrollments'])
            ->and($role(OrganizationRole::Guardian)->permissions)->toBeEmpty();
    });

    it('sync-roles completa los roles vacíos y no toca los que cambió el administrador', function () {
        $treasurer = Role::query()->where('organization_id', $this->club->id)->where('name', 'tesorero')->sole();
        $member = Role::query()->where('organization_id', $this->club->id)->where('name', 'vocal')->sole();
        $treasurer->syncPermissions([]);
        $member->syncPermissions(['ViewAny:Charge']);

        $this->artisan(SyncOrganizationRoles::class)->assertSuccessful();

        expect($treasurer->fresh()->hasPermissionTo('Create:Payment'))->toBeTrue()
            ->and($member->fresh()->permissions->pluck('name')->all())->toBe(['ViewAny:Charge']);
    });

    it('quien no es administrador solo invita a tutores y técnicos', function () {
        dashboardUser(OrganizationRole::Secretary);
        expect(array_keys(RoleFields::options()))->toBe(['instructor', 'tutor']);

        dashboardUser(OrganizationRole::Admin);
        expect(RoleFields::options())->toHaveKey('admin')->toHaveKey('tesorero');
    });
});
