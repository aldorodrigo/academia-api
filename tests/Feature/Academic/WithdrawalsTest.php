<?php

use App\Actions\Billing\IssueSeasonCharges;
use App\Actions\Billing\RegisterPayment;
use App\Actions\Billing\WaiveCharges;
use App\Actions\Enrollments\ReactivateEnrollment;
use App\Actions\Enrollments\WithdrawEnrollment;
use App\Enums\ChargeStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\OrganizationRole;
use App\Enums\PaymentMethod;
use App\Filament\Pages\Reports;
use App\Filament\Resources\Charges\Pages\ManageCharges;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Pages\ManageEnrollments;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Filament\Resources\Students\RelationManagers\ChargesRelationManager;
use App\Filament\Resources\Students\RelationManagers\EnrollmentsRelationManager;
use App\Filament\Support\ChargeHistory;
use App\Models\Charge;
use App\Models\Enrollment;
use App\Models\Family;
use App\Models\FeeConcept;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Season;
use App\Models\Student;
use App\Models\Tariff;
use App\Models\User;
use App\Notifications\DropoutReported;
use App\Reports\DelinquentsReport;
use App\Reports\FamilyBalancesReport;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Bajas y condonación (docs/PLAN_BAJAS.md, hallazgo F4): la baja va con fecha y motivo, la deuda
 * queda como histórica hasta que se paga o se condona, y Morosos/Saldos marcan a los dados de baja.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-03 10:00', 'America/Asuncion'));
    Notification::fake();

    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    app(CurrentOrganization::class)->set($this->jakare);

    $this->season = Season::factory()->for($this->jakare)->create([
        'name' => '2026', 'starts_on' => '2026-02-01', 'ends_on' => '2026-11-30', 'fee_frequency' => 'mensual',
    ]);
    $this->futbol = Program::factory()->for($this->jakare)->create(['name' => 'Fútbol']);
    $this->sub10 = Group::factory()->for($this->futbol)->create(['name' => 'Sub-10', 'organization_id' => $this->jakare->id]);
    Tariff::factory()->create(['fee_concept_id' => FeeConcept::monthlyFee($this->jakare)->id, 'season_id' => $this->season->id, 'amount' => 150000, 'valid_from' => '2026-02-01']);

    $this->family = Family::factory()->for($this->jakare)->create(['name' => 'Familia Zárate']);
    $this->guardian = Guardian::factory()->for($this->jakare)->create(['family_id' => $this->family->id, 'first_name' => 'Rosa', 'last_name' => 'Zárate']);
    $this->matias = Student::factory()->for($this->jakare)->create(['first_name' => 'Matías', 'last_name' => 'Zárate', 'family_id' => $this->family->id]);
    $this->guardian->students()->attach($this->matias);
    $this->enrollment = Enrollment::withoutSeasonCharges(fn () => Enrollment::factory()->create([
        'student_id' => $this->matias->id, 'group_id' => $this->sub10->id, 'season_id' => $this->season->id, 'enrolled_on' => '2026-04-01',
    ]));
    // Abril, mayo y junio (emitida el 1/6) sin pagar.
    foreach (['2026-04', '2026-05', '2026-06'] as $month) {
        issueMonth($this->jakare, $month);
    }

    $this->admin = memberOf($this->jakare, ['name' => 'Admin Jakare']);
    app(RoleAssigner::class)->assign($this->jakare, $this->admin, OrganizationRole::Admin);
});

function withdrawalCharges(Student $student, bool $withVoided = false)
{
    return Charge::query()->where('student_id', $student->id)
        ->when(! $withVoided, fn ($query) => $query->whereNull('voided_at'))
        ->with(['allocations.payment', 'organization'])
        ->orderBy('period_start')
        ->get();
}

function withdrawalMember(OrganizationRole $role, string $name): User
{
    $user = memberOf(test()->jakare, ['name' => $name]);
    app(RoleAssigner::class)->assign(test()->jakare, $user, $role, endsOn: $role === OrganizationRole::Instructor ? null : now()->addYear());

    return $user;
}

function withdrawalPanel(User $user): void
{
    test()->actingAs($user);
    filament()->setTenant(test()->jakare);
    app(CurrentOrganization::class)->set(test()->jakare);
}

describe('dar de baja', function () {
    it('guarda fecha, motivo y quién; la deuda (también la del mes en curso) queda pendiente', function () {
        app(WithdrawEnrollment::class)->handle($this->enrollment, '2026-06-03', 'Se mudó a Encarnación', $this->admin);

        $enrollment = $this->enrollment->fresh();
        expect($enrollment->status)->toBe(EnrollmentStatus::Withdrawn)
            ->and($enrollment->ended_on->toDateString())->toBe('2026-06-03')
            ->and($enrollment->withdrawal_reason)->toBe('Se mudó a Encarnación')
            ->and($enrollment->withdrawn_by)->toBe($this->admin->id);

        $charges = withdrawalCharges($this->matias);
        expect($charges)->toHaveCount(3)
            ->and($charges->sum(fn (Charge $c) => $c->pendingAmount()))->toBe(450000)
            ->and($charges->last()->period_start->toDateString())->toBe('2026-06-01');

        expect(Activity::query()->where('log_name', 'academic')->where('description', 'Baja de la inscripción')->sole()->causer_id)->toBe($this->admin->id);
    });

    it('anula solas solo las cuotas futuras sin pagar, aunque la fecha de baja sea anterior al mes en curso', function () {
        $this->season->update(['issue_upfront' => true]);
        app(IssueSeasonCharges::class)->forEnrollment($this->enrollment->fresh());
        expect(withdrawalCharges($this->matias))->toHaveCount(8); // abril a noviembre

        $preview = app(WithdrawEnrollment::class)->preview($this->enrollment->fresh());
        expect($preview)->toBe(['pending_count' => 3, 'pending_amount' => 450000, 'future_count' => 5]);

        app(WithdrawEnrollment::class)->handle($this->enrollment->fresh(), '2026-05-31', 'Dejó de venir', $this->admin);

        expect(withdrawalCharges($this->matias)->map(fn (Charge $c) => $c->period_start->format('m'))->all())->toBe(['04', '05', '06']);
    });

    it('valida la fecha, el motivo y que no esté dada de baja', function (string $on, string $reason, string $field) {
        expect(fn () => app(WithdrawEnrollment::class)->handle($this->enrollment, $on, $reason, $this->admin))
            ->toThrow(ValidationException::class);

        expect($this->enrollment->fresh()->status)->toBe(EnrollmentStatus::Active);
    })->with([
        'a futuro' => ['2026-06-04', 'Se mudó', 'ended_on'],
        'antes de inscribirse' => ['2026-03-31', 'Se mudó', 'ended_on'],
        'sin motivo' => ['2026-06-03', '  ', 'withdrawal_reason'],
    ]);

    it('no se da de baja dos veces', function () {
        app(WithdrawEnrollment::class)->handle($this->enrollment, '2026-06-03', 'Se mudó', $this->admin);

        expect(fn () => app(WithdrawEnrollment::class)->handle($this->enrollment->fresh(), '2026-06-03', 'Otra vez', $this->admin))
            ->toThrow(ValidationException::class, 'ya está dada de baja');
    });
});

describe('si vuelve', function () {
    it('la deuda sigue y no se cobran los meses que estuvo afuera', function () {
        app(WithdrawEnrollment::class)->handle($this->enrollment, '2026-06-03', 'Se mudó', $this->admin);
        $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00', 'America/Asuncion'));

        $pending = app(ReactivateEnrollment::class)->handle($this->enrollment->fresh(), EnrollmentStatus::Active, $this->admin);

        $enrollment = $this->enrollment->fresh();
        expect($enrollment->status)->toBe(EnrollmentStatus::Active)
            ->and($enrollment->ended_on)->toBeNull()
            ->and($enrollment->withdrawal_reason)->toBeNull();

        expect(withdrawalCharges($this->matias)->map(fn (Charge $c) => $c->period_start->format('m'))->all())->toBe(['04', '05', '06', '09'])
            ->and($pending)->toBe(600000);
    });

    it('con las cuotas por adelantado, se reemiten desde el mes en curso', function () {
        $this->season->update(['issue_upfront' => true]);
        app(IssueSeasonCharges::class)->forEnrollment($this->enrollment->fresh());
        app(WithdrawEnrollment::class)->handle($this->enrollment->fresh(), '2026-06-03', 'Se mudó', $this->admin);
        $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00', 'America/Asuncion'));

        app(ReactivateEnrollment::class)->handle($this->enrollment->fresh(), EnrollmentStatus::Active, $this->admin);

        expect(withdrawalCharges($this->matias)->map(fn (Charge $c) => $c->period_start->format('m'))->all())
            ->toBe(['04', '05', '06', '09', '10', '11']);
    });

    it('de pendiente a activo se sigue cobrando desde la inscripción', function () {
        $pending = Enrollment::withoutSeasonCharges(fn () => Enrollment::factory()->create([
            'student_id' => Student::factory()->for($this->jakare)->create()->id,
            'group_id' => $this->sub10->id, 'season_id' => $this->season->id, 'enrolled_on' => '2026-05-01', 'status' => EnrollmentStatus::Pending,
        ]));

        $pending->update(['status' => EnrollmentStatus::Active]);

        expect(Charge::query()->where('enrollment_id', $pending->id)->count())->toBe(2); // mayo y junio
    });
});

describe('condonar', function () {
    it('el admin condona lo pendiente con motivo; lo pagado sigue siendo ingreso', function () {
        $cash = MoneyAccount::query()->where('name', 'Caja')->sole();
        [$april, $may, $june] = withdrawalCharges($this->matias)->all();
        app(RegisterPayment::class)->handle($this->family, $cash, 50000, PaymentMethod::Cash, CarbonImmutable::parse('2026-05-02'), allocations: [$april->id => 50000]);

        $total = app(WaiveCharges::class)->handle(withdrawalCharges($this->matias), 'Dado de baja: lo decidió la comisión', $this->admin);

        expect($total)->toBe(400000);
        $april = $april->fresh();
        expect($april->status())->toBe(ChargeStatus::Waived)
            ->and($april->waived_amount)->toBe(100000)
            ->and($april->paidAmount())->toBe(50000)
            ->and($april->voided_by)->toBe($this->admin->id)
            ->and($april->void_reason)->toBe('Dado de baja: lo decidió la comisión')
            ->and($june->fresh()->unique_key)->not->toBeNull(); // el generador no la vuelve a emitir

        expect(collect(ChargeHistory::for($april))->pluck('text'))->toContain('Condonada ₲ 100.000: Dado de baja: lo decidió la comisión');

        // Sale de la cuenta, de saldos y de morosos.
        expect(withdrawalCharges($this->matias))->toBeEmpty()
            ->and((new DelinquentsReport($this->jakare))->data()['families'])->toBeEmpty();
    });

    it('sin el permiso "Condonar deudas" no se puede; con el permiso, sí', function () {
        $treasurer = withdrawalMember(OrganizationRole::Treasurer, 'Laura Gómez');
        app(CurrentOrganization::class)->set($this->jakare);

        expect(fn () => app(WaiveCharges::class)->handle(withdrawalCharges($this->matias), 'Baja', $treasurer))
            ->toThrow(ValidationException::class, 'No tenés permiso');

        Permission::findOrCreate(WaiveCharges::PERMISSION, 'web');
        Role::query()->where('organization_id', $this->jakare->id)->where('name', 'tesorero')->sole()->givePermissionTo(WaiveCharges::PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        expect(app(WaiveCharges::class)->handle(withdrawalCharges($this->matias), 'Baja', $treasurer->fresh()))->toBe(450000);
    });

    it('pide motivo y no condona cuotas pagadas ni anuladas', function () {
        $charges = withdrawalCharges($this->matias);

        expect(fn () => app(WaiveCharges::class)->handle($charges, ' ', $this->admin))->toThrow(ValidationException::class, 'motivo');

        app(WaiveCharges::class)->handle([$charges->first()], 'Baja', $this->admin);
        expect(fn () => app(WaiveCharges::class)->handle([$charges->first()->fresh()], 'Otra vez', $this->admin))
            ->toThrow(ValidationException::class, 'Solo se condonan');
    });
});

describe('morosos y saldos', function () {
    beforeEach(function () {
        // Diego sigue (también debe); Matías se va. Una cuota vencida alcanza para ser moroso.
        $this->travelTo(CarbonImmutable::parse('2026-06-25 10:00', 'America/Asuncion'));
        $this->diego = Student::factory()->for($this->jakare)->create(['first_name' => 'Diego', 'last_name' => 'Ortiz']);
        Enrollment::withoutSeasonCharges(fn () => Enrollment::factory()->create(['student_id' => $this->diego->id, 'group_id' => $this->sub10->id, 'season_id' => $this->season->id, 'enrolled_on' => '2026-06-01']));
        issueMonth($this->jakare, '2026-06');
        app(WithdrawEnrollment::class)->handle($this->enrollment, '2026-06-03', 'Se mudó', $this->admin);
    });

    it('marcan a los dados de baja con la fecha y se filtran', function () {
        $all = (new DelinquentsReport($this->jakare))->data()['families'];
        expect(collect($all)->pluck('withdrawn', 'family')->all())->toBe([
            'Familia Zárate' => [['student_id' => $this->matias->id, 'student' => 'Matías', 'on' => '2026-06-03']],
            'Diego Ortiz' => [],
        ]);

        expect(collect((new DelinquentsReport($this->jakare, withdrawn: 'only'))->data()['families'])->pluck('family')->all())->toBe(['Familia Zárate'])
            ->and(collect((new DelinquentsReport($this->jakare, withdrawn: 'exclude'))->data()['families'])->pluck('family')->all())->toBe(['Diego Ortiz']);

        $balances = collect((new FamilyBalancesReport($this->jakare))->families())->keyBy('family');
        expect($balances['Familia Zárate']['withdrawn'][0]['on'])->toBe('2026-06-03')
            ->and($balances['Diego Ortiz']['withdrawn'])->toBe([]);

        // En PDF y Excel, una columna más.
        $section = (new DelinquentsReport($this->jakare))->sections()[1];
        expect(end($section['headers']))->toBe('Dados de baja')
            ->and(collect($section['rows'])->firstWhere(0, 'Familia Zárate')[7])->toBe('Matías (03/06/2026)');
    });

    it('si sigue en otra disciplina no está dado de baja', function () {
        $padel = Group::factory()->for(Program::factory()->for($this->jakare)->create(['name' => 'Pádel']))->create(['organization_id' => $this->jakare->id]);
        Enrollment::withoutSeasonCharges(fn () => Enrollment::factory()->create(['student_id' => $this->matias->id, 'group_id' => $padel->id, 'season_id' => $this->season->id]));

        expect(collect((new DelinquentsReport($this->jakare, withdrawn: 'only'))->data()['families']))->toBeEmpty();
    });

    it('la API filtra y lleva el filtro al link de descarga', function () {
        $treasurer = withdrawalMember(OrganizationRole::Treasurer, 'Laura Gómez');

        $response = $this->actingAs($treasurer, 'sanctum')
            ->getJson('/api/v1/reports/delinquents?withdrawn=only', ['X-Organization' => 'jakare'])
            ->assertOk()
            ->assertJsonPath('data.families.0.family', 'Familia Zárate')
            ->assertJsonPath('data.families.0.withdrawn.0.on', '2026-06-03')
            ->assertJsonCount(1, 'data.families');
        expect($response->json('data.pdf_url'))->toContain('withdrawn=only');

        $this->get($response->json('data.xlsx_url'))->assertOk();

        $this->actingAs($treasurer, 'sanctum')
            ->getJson('/api/v1/reports/delinquents?withdrawn=todos', ['X-Organization' => 'jakare'])
            ->assertUnprocessable();

        $this->actingAs($treasurer, 'sanctum')
            ->getJson('/api/v1/reports/balances', ['X-Organization' => 'jakare'])
            ->assertOk()
            ->assertJsonPath('data.families.0.withdrawn.0.student', 'Matías');
    });

    it('el panel filtra los morosos dados de baja', function () {
        withdrawalPanel($this->admin);

        Livewire::test(Reports::class, ['tab' => 'morosos'])
            ->set('withdrawn', 'only')
            ->assertSee('Matías: baja el 03/06/2026')
            ->assertDontSee('Diego Ortiz');
    });
});

describe('aviso del técnico', function () {
    beforeEach(function () {
        $this->instructor = withdrawalMember(OrganizationRole::Instructor, 'Carlos Gómez');
        $this->sub10->instructors()->attach($this->instructor);
        Schedule::factory()->create(['group_id' => $this->sub10->id, 'weekday' => 1, 'starts_at' => '17:00', 'ends_at' => '18:30']);
        $this->secretary = withdrawalMember(OrganizationRole::Secretary, 'Sonia Secretaria');
        $this->treasurer = withdrawalMember(OrganizationRole::Treasurer, 'Laura Gómez');
        app(CurrentOrganization::class)->set($this->jakare);
    });

    it('avisa que dejó de venir; les llega a quienes pueden dar la baja y lo puede deshacer', function () {
        $this->actingAs($this->instructor, 'sanctum')
            ->postJson("/api/v1/groups/{$this->sub10->id}/students/{$this->matias->id}/dropout", ['note' => 'No viene hace 3 semanas'], ['X-Organization' => 'jakare'])
            ->assertOk()
            ->assertJsonPath('data.dropout_reported_on', '2026-06-03');

        $enrollment = $this->enrollment->fresh();
        expect($enrollment->status)->toBe(EnrollmentStatus::Active)
            ->and($enrollment->dropout_reported_by)->toBe($this->instructor->id)
            ->and($enrollment->dropout_note)->toBe('No viene hace 3 semanas');

        Notification::assertSentTo([$this->admin, $this->secretary], DropoutReported::class,
            fn (DropoutReported $n) => str_contains($n->body, 'Carlos Gómez avisó que Matías Zárate (Sub-10) dejó de venir: «No viene hace 3 semanas»'));
        Notification::assertNotSentTo([$this->treasurer, $this->instructor], DropoutReported::class);

        $this->actingAs($this->instructor, 'sanctum')
            ->getJson("/api/v1/groups/{$this->sub10->id}?month=2026-06", ['X-Organization' => 'jakare'])
            ->assertOk()
            ->assertJsonPath('data.students.0.dropout_reported_on', '2026-06-03');

        $this->actingAs($this->instructor, 'sanctum')
            ->deleteJson("/api/v1/groups/{$this->sub10->id}/students/{$this->matias->id}/dropout", [], ['X-Organization' => 'jakare'])
            ->assertOk();
        expect($this->enrollment->fresh()->dropout_reported_at)->toBeNull();
    });

    it('solo en sus grupos y con alumnos activos', function () {
        $other = withdrawalMember(OrganizationRole::Instructor, 'Otro Técnico');

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/groups/{$this->sub10->id}/students/{$this->matias->id}/dropout", [], ['X-Organization' => 'jakare'])
            ->assertNotFound();

        app(WithdrawEnrollment::class)->handle($this->enrollment, '2026-06-03', 'Se mudó', $this->admin);
        $this->actingAs($this->instructor, 'sanctum')
            ->postJson("/api/v1/groups/{$this->sub10->id}/students/{$this->matias->id}/dropout", [], ['X-Organization' => 'jakare'])
            ->assertNotFound();
    });

    it('la baja cierra el aviso', function () {
        $this->actingAs($this->instructor, 'sanctum')
            ->postJson("/api/v1/groups/{$this->sub10->id}/students/{$this->matias->id}/dropout", [], ['X-Organization' => 'jakare'])
            ->assertOk();

        app(WithdrawEnrollment::class)->handle($this->enrollment->fresh(), '2026-06-03', 'Dejó de venir', $this->admin);

        expect($this->enrollment->fresh()->dropout_reported_at)->toBeNull();
    });
});

describe('panel', function () {
    it('Inscripciones: aviso del técnico, dar de baja con fecha y motivo, reactivar', function () {
        $this->enrollment->update(['dropout_reported_at' => now(), 'dropout_reported_by' => $this->admin->id, 'dropout_note' => 'Se mudó']);
        withdrawalPanel($this->admin);

        expect(EnrollmentResource::getNavigationBadge())->toBe('1');

        Livewire::test(ManageEnrollments::class)
            ->filterTable('dropout', true)
            ->assertCanSeeTableRecords([$this->enrollment])
            ->assertSee('Admin Jakare avisó que dejó de venir')
            ->assertTableActionHidden('reactivate', $this->enrollment)
            ->mountTableAction('withdraw', $this->enrollment)
            ->assertMountedActionModalSee('Quedan pendientes 3 cuotas por ₲ 450.000 (también la del período en curso)')
            ->assertTableActionDataSet(['withdrawal_reason' => 'Se mudó', 'ended_on' => '2026-06-03'])
            ->setTableActionData(['ended_on' => '2026-06-01'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $enrollment = $this->enrollment->fresh();
        expect($enrollment->status)->toBe(EnrollmentStatus::Withdrawn)
            ->and($enrollment->ended_on->toDateString())->toBe('2026-06-01')
            ->and($enrollment->withdrawal_reason)->toBe('Se mudó')
            ->and(EnrollmentResource::getNavigationBadge())->toBeNull();

        Livewire::test(ManageEnrollments::class)
            ->assertTableActionHidden('changeStatus', $enrollment)
            ->assertTableActionHidden('withdraw', $enrollment)
            ->callTableAction('reactivate', $enrollment, data: ['status' => EnrollmentStatus::Active->value])
            ->assertNotified();

        expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::Active);
    });

    it('"Sigue viniendo" descarta el aviso', function () {
        $this->enrollment->update(['dropout_reported_at' => now(), 'dropout_reported_by' => $this->admin->id]);
        withdrawalPanel($this->admin);

        Livewire::test(ManageEnrollments::class)->callTableAction('dismissDropout', $this->enrollment);

        expect($this->enrollment->fresh()->dropout_reported_at)->toBeNull()
            ->and($this->enrollment->fresh()->status)->toBe(EnrollmentStatus::Active);
    });

    it('"Estado" ya no ofrece la baja: va con fecha y motivo', function () {
        withdrawalPanel($this->admin);

        Livewire::test(ManageEnrollments::class)
            ->callTableAction('changeStatus', $this->enrollment, data: ['status' => EnrollmentStatus::Withdrawn->value])
            ->assertHasTableActionErrors(['status']);

        expect($this->enrollment->fresh()->status)->toBe(EnrollmentStatus::Active);
    });

    it('ficha: baja desde Inscripciones y condonación desde Cuenta', function () {
        withdrawalPanel($this->admin);

        Livewire::test(EnrollmentsRelationManager::class, ['ownerRecord' => $this->matias, 'pageClass' => EditStudent::class])
            ->callTableAction('withdraw', $this->enrollment, data: ['ended_on' => '2026-06-03', 'withdrawal_reason' => 'Cambió de club'])
            ->assertHasNoTableActionErrors()
            ->assertSee('Cambió de club');

        $june = withdrawalCharges($this->matias)->last();
        Livewire::test(ChargesRelationManager::class, ['ownerRecord' => $this->matias, 'pageClass' => EditStudent::class])
            ->callTableAction('waive', $june, data: ['reason' => 'Dado de baja'])
            ->assertHasNoTableActionErrors();

        Livewire::test(ChargesRelationManager::class, ['ownerRecord' => $this->matias, 'pageClass' => EditStudent::class])
            ->callTableBulkAction('waive', withdrawalCharges($this->matias), data: ['reason' => 'Dado de baja'])
            ->assertHasNoTableActionErrors()
            ->assertSee('Condonado');

        expect(withdrawalCharges($this->matias))->toBeEmpty()
            ->and(withdrawalCharges($this->matias, withVoided: true)->every(fn (Charge $c) => $c->isWaived()))->toBeTrue();
    });

    it('sin el permiso no se ve "Condonar" en Cargos', function () {
        $treasurer = withdrawalMember(OrganizationRole::Treasurer, 'Laura Gómez');
        withdrawalPanel($treasurer);
        $charge = withdrawalCharges($this->matias)->first();

        Livewire::test(ManageCharges::class)
            ->assertTableActionHidden('waive', $charge)
            ->assertTableActionVisible('void', $charge);

        withdrawalPanel($this->admin);
        Livewire::test(ManageCharges::class)
            ->callTableAction('waive', $charge, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);
        Livewire::test(ManageCharges::class)
            ->callTableAction('waive', $charge, data: ['reason' => 'Lo decidió la comisión'])
            ->assertHasNoTableActionErrors();

        expect($charge->fresh()->status())->toBe(ChargeStatus::Waived);
    });
});
