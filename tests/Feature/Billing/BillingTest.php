<?php

use App\Actions\Billing\CreateManualCharges;
use App\Actions\Billing\GenerateMonthlyCharges;
use App\Actions\Billing\ScholarshipDecision;
use App\Actions\Billing\VoidCharge;
use App\Enums\DiscountType;
use App\Enums\EnrollmentStatus;
use App\Enums\OrganizationRole;
use App\Models\Charge;
use App\Models\DiscountRule;
use App\Models\Enrollment;
use App\Models\Family;
use App\Models\FeeConcept;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Role;
use App\Models\Scholarship;
use App\Models\Season;
use App\Models\Student;
use App\Models\Tariff;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->travelTo('2026-09-15 12:00:00');
    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    app(CurrentOrganization::class)->set($this->jakare);

    $this->season = Season::factory()->for($this->jakare)->create(['name' => '2026', 'starts_on' => '2026-02-01', 'ends_on' => '2026-11-30', 'is_current' => true]);
    $futbol = Program::factory()->for($this->jakare)->create(['name' => 'Fútbol']);
    $this->sub10 = Group::factory()->for($futbol)->create(['name' => 'Sub-10', 'organization_id' => $this->jakare->id]);
    $this->sub8 = Group::factory()->for($futbol)->create(['name' => 'Sub-8', 'organization_id' => $this->jakare->id]);
    $this->monthly = FeeConcept::monthlyFee($this->jakare);
    Tariff::factory()->create(['fee_concept_id' => $this->monthly->id, 'season_id' => $this->season->id, 'amount' => 150000, 'valid_from' => '2026-02-01']);

    $family = Family::factory()->for($this->jakare)->create();
    $this->mateo = Student::factory()->for($this->jakare)->create(['first_name' => 'Mateo', 'birth_date' => '2016-03-14', 'family_id' => $family->id]);
    $this->sofia = Student::factory()->for($this->jakare)->create(['first_name' => 'Sofía', 'birth_date' => '2018-07-02', 'family_id' => $family->id]);
    $this->enrollMateo = Enrollment::factory()->create(['student_id' => $this->mateo->id, 'group_id' => $this->sub10->id, 'season_id' => $this->season->id]);
    $this->enrollSofia = Enrollment::factory()->create(['student_id' => $this->sofia->id, 'group_id' => $this->sub8->id, 'season_id' => $this->season->id]);
});

function generate(string $period = '2026-09', bool $dryRun = false): array
{
    return app(GenerateMonthlyCharges::class)->handle(test()->jakare, CarbonImmutable::parse("{$period}-01"), $dryRun);
}

function siblingsRule(int $position = 2, int $percent = 20): DiscountRule
{
    $rule = DiscountRule::factory()->for(test()->jakare)->create(['sibling_position' => $position, 'percent' => $percent]);
    $rule->feeConcepts()->attach(test()->monthly);

    return $rule;
}

describe('tarifas', function () {
    it('gana la de la categoría y la vigencia más reciente', function () {
        Tariff::factory()->create(['fee_concept_id' => $this->monthly->id, 'season_id' => $this->season->id, 'group_id' => $this->sub10->id, 'amount' => 170000, 'valid_from' => '2026-02-01']);
        Tariff::factory()->create(['fee_concept_id' => $this->monthly->id, 'season_id' => $this->season->id, 'amount' => 160000, 'valid_from' => '2026-10-01']);

        expect(Tariff::applicable($this->monthly, $this->season, $this->sub10, CarbonImmutable::parse('2026-09-01'))->amount)->toBe(170000)
            ->and(Tariff::applicable($this->monthly, $this->season, $this->sub8, CarbonImmutable::parse('2026-09-01'))->amount)->toBe(150000)
            ->and(Tariff::applicable($this->monthly, $this->season, $this->sub8, CarbonImmutable::parse('2026-10-01'))->amount)->toBe(160000);
    });

    it('una tarifa nueva no cambia los cargos emitidos', function () {
        generate('2026-09');
        Tariff::factory()->create(['fee_concept_id' => $this->monthly->id, 'season_id' => $this->season->id, 'amount' => 200000, 'valid_from' => '2026-09-01']);

        expect(Charge::query()->pluck('base_amount')->unique()->all())->toBe([150000]);
    });
});

describe('cuota mensual automática', function () {
    it('genera una por inscripción facturable, con vencimiento y descripción', function () {
        $summary = generate('2026-09');

        expect($summary['created'])->toBe(2);
        $charge = Charge::query()->where('student_id', $this->mateo->id)->sole();
        expect($charge->description)->toBe('Cuota septiembre 2026')
            ->and($charge->due_on->toDateString())->toBe('2026-09-10')
            ->and($charge->period->toDateString())->toBe('2026-09-01')
            ->and($charge->group_id)->toBe($this->sub10->id);
    });

    it('es idempotente y respeta el lock', function () {
        generate('2026-09');
        expect(generate('2026-09'))->toMatchArray(['created' => 0, 'existing' => 2])
            ->and(Charge::query()->count())->toBe(2);

        $lock = Cache::lock("charges:{$this->jakare->id}:2026-10", 60);
        $lock->get();
        expect(fn () => generate('2026-10'))->toThrow(RuntimeException::class, 'Ya se están generando');
        $lock->release();
    });

    it('no cobra bajas, temporadas anteriores ni becas totales, y avisa lo que falta', function () {
        $this->enrollSofia->update(['status' => EnrollmentStatus::Withdrawn]);
        $old = Season::factory()->for($this->jakare)->create(['name' => '2025']);
        Enrollment::factory()->create(['student_id' => $this->sofia->id, 'group_id' => $this->sub8->id, 'season_id' => $old->id]);
        Scholarship::factory()->approved()->create(['enrollment_id' => $this->enrollMateo->id, 'percent' => 100]);
        $sub12 = Group::factory()->create(['name' => 'Sub-12', 'program_id' => $this->sub10->program_id, 'organization_id' => $this->jakare->id]);
        Tariff::query()->delete();
        Tariff::factory()->create(['fee_concept_id' => $this->monthly->id, 'season_id' => $this->season->id, 'group_id' => $this->sub10->id, 'amount' => 1, 'valid_from' => '2026-02-01']);
        Enrollment::factory()->create(['student_id' => Student::factory()->for($this->jakare)->create()->id, 'group_id' => $sub12->id, 'season_id' => $this->season->id]);

        expect(generate('2026-09'))->toMatchArray(['created' => 0, 'full_scholarship' => 1, 'without_tariff' => ['Sub-12']]);
    });

    it('fuera de la temporada no genera', function () {
        expect(generate('2026-12'))->toMatchArray(['created' => 0, 'out_of_season' => true]);
    });

    it('la vista previa no crea nada', function () {
        expect(generate('2026-09', dryRun: true)['created'])->toBe(2)
            ->and(Charge::query()->count())->toBe(0);
    });

    it('vence el último día en meses cortos', function () {
        $this->jakare->update(['billing' => ['due_day' => 31]]);

        generate('2026-02');

        expect(Charge::query()->first()->due_on->toDateString())->toBe('2026-02-28');
    });
});

describe('descuentos y becas', function () {
    it('hermanos: el mayor paga completo y el 2º tiene descuento', function () {
        siblingsRule();

        generate('2026-09');

        $mateo = Charge::query()->where('student_id', $this->mateo->id)->sole();
        $sofia = Charge::query()->where('student_id', $this->sofia->id)->sole();
        expect($mateo->final_amount)->toBe(150000)
            ->and($sofia->final_amount)->toBe(120000)
            ->and($sofia->adjustments->sole()->label)->toBe('Hermanos (2º hijo) −20 %');
    });

    it('la regla de 3 vale del 3º en adelante', function () {
        siblingsRule(2, 20);
        siblingsRule(3, 50);
        $third = Student::factory()->for($this->jakare)->create(['birth_date' => '2019-01-01', 'family_id' => $this->mateo->family_id]);
        Enrollment::factory()->create(['student_id' => $third->id, 'group_id' => $this->sub8->id, 'season_id' => $this->season->id]);

        generate('2026-09');

        expect(Charge::query()->where('student_id', $third->id)->sole()->final_amount)->toBe(75000);
    });

    it('beca parcial y hermanos en el orden configurado, redondeado al guaraní', function () {
        siblingsRule();
        Scholarship::factory()->approved()->create(['enrollment_id' => $this->enrollSofia->id, 'percent' => 33]);

        generate('2026-09');
        $sofia = Charge::query()->where('student_id', $this->sofia->id)->sole();
        // Beca primero: 150.000 − 49.500 = 100.500; hermanos −20 %: − 20.100 = 80.400.
        expect($sofia->adjustments->pluck('amount')->all())->toBe([-49500, -20100])
            ->and($sofia->final_amount)->toBe(80400);

        // Con hermanos primero cambia el resultado.
        $this->jakare->update(['billing' => ['discount_order' => ['hermanos', 'beca', 'convenio', 'otro']]]);
        generate('2026-10');
        expect(Charge::query()->where('student_id', $this->sofia->id)->where('period', '2026-10-01')->sole()->final_amount)->toBe(80400)
            ->and(Charge::query()->where('student_id', $this->sofia->id)->where('period', '2026-10-01')->sole()->adjustments->pluck('amount')->all())
            ->toBe([-30000, -39600]);
    });

    it('nunca queda negativo y el convenio es solo para sus jugadores', function () {
        $rule = DiscountRule::factory()->for($this->jakare)->create(['type' => DiscountType::Agreement, 'name' => 'Convenio Itaú', 'percent' => null, 'fixed_amount' => 999999, 'sibling_position' => null]);
        $rule->feeConcepts()->attach($this->monthly);
        $rule->students()->attach($this->mateo);

        generate('2026-09');

        expect(Charge::query()->where('student_id', $this->mateo->id)->sole()->final_amount)->toBe(0)
            ->and(Charge::query()->where('student_id', $this->sofia->id)->sole()->final_amount)->toBe(150000);
    });

    it('la beca se aprueba con el permiso y la inscripción pasa a becado', function () {
        $decision = app(ScholarshipDecision::class);
        $treasurer = memberOf($this->jakare);
        app(RoleAssigner::class)->assign($this->jakare, $treasurer, OrganizationRole::Treasurer, endsOn: now()->addYear());
        $scholarship = $decision->request($this->enrollSofia, 50, 'Situación económica', now(), null, $treasurer);

        // Sin el permiso no puede aprobar.
        expect(fn () => $decision->approve($scholarship, $treasurer))->toThrow(AuthorizationException::class);

        Permission::findOrCreate('Approve:Scholarship');
        Role::query()->where('organization_id', $this->jakare->id)->where('name', 'tesorero')->sole()->givePermissionTo('Approve:Scholarship');
        $decision->approve($scholarship, $treasurer->fresh());

        expect($scholarship->fresh()->status->value)->toBe('aprobada')
            ->and($this->enrollSofia->fresh()->status)->toBe(EnrollmentStatus::Scholarship);

        $decision->revoke($scholarship->fresh(), $treasurer->fresh(), 'Ya no corresponde');
        expect($this->enrollSofia->fresh()->status)->toBe(EnrollmentStatus::Active);
    });

    it('una beca pendiente no se aplica', function () {
        Scholarship::factory()->create(['enrollment_id' => $this->enrollSofia->id, 'percent' => 50]);

        generate('2026-09');

        expect(Charge::query()->where('student_id', $this->sofia->id)->sole()->final_amount)->toBe(150000);
    });
});

describe('cargos', function () {
    it('cargo de inscripción al inscribir, si hay tarifa', function () {
        $fee = FeeConcept::enrollmentFee($this->jakare);
        Tariff::factory()->create(['fee_concept_id' => $fee->id, 'season_id' => $this->season->id, 'amount' => 100000, 'valid_from' => '2026-02-01']);
        $new = Student::factory()->for($this->jakare)->create();

        $enrollment = Enrollment::factory()->create(['student_id' => $new->id, 'group_id' => $this->sub10->id, 'season_id' => $this->season->id]);

        $charge = Charge::query()->where('enrollment_id', $enrollment->id)->sole();
        expect($charge->description)->toBe('Inscripción 2026')
            ->and($charge->final_amount)->toBe(100000)
            ->and($charge->due_on->toDateString())->toBe('2026-10-10');
    });

    it('cargo manual para toda una categoría', function () {
        $torneo = FeeConcept::factory()->for($this->jakare)->create(['name' => 'Torneo']);

        $count = app(CreateManualCharges::class)->handle($this->jakare, $torneo, 50000, 'Torneo de primavera', CarbonImmutable::parse('2026-10-01'), group: $this->sub10);

        expect($count)->toBe(1)
            ->and(Charge::query()->where('fee_concept_id', $torneo->id)->sole()->student_id)->toBe($this->mateo->id);
    });

    it('un cargo no se edita ni se borra; se anula con motivo', function () {
        generate('2026-09');
        $charge = Charge::query()->first();

        expect(fn () => $charge->update(['final_amount' => 1]))->toThrow(LogicException::class)
            ->and(fn () => $charge->delete())->toThrow(LogicException::class)
            ->and(fn () => app(VoidCharge::class)->handle($charge->fresh(), ' ', memberOf($this->jakare)))->toThrow(ValidationException::class);

        app(VoidCharge::class)->handle($charge->fresh(), 'Cargado por error', $user = memberOf($this->jakare));

        expect($charge->fresh()->status()->value)->toBe('anulado')
            ->and($charge->fresh()->voided_by)->toBe($user->id)
            ->and(Activity::query()->where('log_name', 'billing')->where('subject_id', $charge->id)->where('description', 'updated')->exists())->toBeTrue();
    });

    it('vencido después del vencimiento más la gracia', function () {
        generate('2026-08');
        $charge = Charge::query()->first();

        expect($charge->status()->value)->toBe('vencido');

        $this->jakare->update(['billing' => ['grace_days' => 40]]);
        expect($charge->fresh()->status()->value)->toBe('pendiente');
    });
});

it('el comando genera por organización', function () {
    $this->artisan('charges:generate', ['--organization' => 'jakare', '--period' => '2026-09'])
        ->expectsOutputToContain('2 creadas')
        ->assertSuccessful();

    expect(Charge::query()->count())->toBe(2);
});
