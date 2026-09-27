<?php

use App\Actions\Billing\GenerateMonthlyCharges;
use App\Actions\Billing\VoidCharge;
use App\Models\Charge;
use App\Models\DiscountRule;
use App\Models\Enrollment;
use App\Models\Family;
use App\Models\FeeConcept;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Scholarship;
use App\Models\Season;
use App\Models\Student;
use App\Models\Tariff;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo('2026-09-05 12:00:00');
    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    $this->ajena = Organization::factory()->create(['slug' => 'ajena']);
    app(CurrentOrganization::class)->set($this->jakare);

    $season = Season::factory()->for($this->jakare)->create(['name' => '2026', 'starts_on' => '2026-02-01', 'ends_on' => '2026-11-30', 'is_current' => true]);
    $futbol = Program::factory()->for($this->jakare)->create(['name' => 'Fútbol']);
    $sub10 = Group::factory()->for($futbol)->create(['name' => 'Sub-10', 'organization_id' => $this->jakare->id]);
    $sub8 = Group::factory()->for($futbol)->create(['name' => 'Sub-8', 'organization_id' => $this->jakare->id]);
    $monthly = FeeConcept::monthlyFee($this->jakare);
    Tariff::factory()->create(['fee_concept_id' => $monthly->id, 'season_id' => $season->id, 'amount' => 150000, 'valid_from' => '2026-02-01']);
    DiscountRule::factory()->for($this->jakare)->create()->feeConcepts()->attach($monthly);

    $this->user = memberOf($this->jakare);
    $family = Family::factory()->for($this->jakare)->create();
    $guardian = Guardian::factory()->for($this->jakare)->create(['user_id' => $this->user->id, 'family_id' => $family->id]);
    $this->mateo = Student::factory()->for($this->jakare)->create(['first_name' => 'Mateo', 'last_name' => 'Benítez', 'birth_date' => '2016-03-14', 'family_id' => $family->id]);
    $this->sofia = Student::factory()->for($this->jakare)->create(['first_name' => 'Sofía', 'last_name' => 'Benítez', 'birth_date' => '2018-07-02', 'family_id' => $family->id]);
    $guardian->students()->attach([$this->mateo->id, $this->sofia->id]);
    Enrollment::factory()->create(['student_id' => $this->mateo->id, 'group_id' => $sub10->id, 'season_id' => $season->id]);
    $sofiaEnrollment = Enrollment::factory()->create(['student_id' => $this->sofia->id, 'group_id' => $sub8->id, 'season_id' => $season->id]);
    Scholarship::factory()->approved()->create(['enrollment_id' => $sofiaEnrollment->id, 'percent' => 50]);

    $generate = app(GenerateMonthlyCharges::class);
    $generate->handle($this->jakare, CarbonImmutable::parse('2026-08-01'));
    $generate->handle($this->jakare, CarbonImmutable::parse('2026-09-01'));
});

function accountApi(User $user, string $uri, string $organization = 'jakare')
{
    return test()->actingAs($user, 'sanctum')->getJson("/api/v1/{$uri}", ['X-Organization' => $organization]);
}

it('consolidado de la familia con saldos, vencidos y detalle de cada cargo', function () {
    // Mateo 150.000 × 2; Sofía: beca 50 % y hermanos −20 % → 60.000 × 2. Agosto está vencido.
    accountApi($this->user, 'account')
        ->assertOk()
        ->assertJsonPath('data.balance', 420000)
        ->assertJsonPath('data.overdue', 210000)
        ->assertJsonPath('data.students.0.full_name', 'Mateo Benítez')
        ->assertJsonPath('data.students.0.balance', 300000)
        ->assertJsonPath('data.students.1.balance', 120000)
        ->assertJsonCount(4, 'data.charges')
        ->assertJsonPath('data.charges.0.due_on', '2026-09-10')
        ->assertJsonPath('data.charges.0.status', 'pendiente')
        ->assertJsonPath('data.charges.0.period', '2026-09')
        ->assertJsonPath('data.charges.0.concept', 'Cuota mensual')
        ->assertJsonPath('data.charges.3.status', 'vencido')
        ->assertJsonPath('data.charges.3.status_label', 'Vencido');

    $sofia = collect(accountApi($this->user, 'account')->json('data.charges'))->firstWhere('student.first_name', 'Sofía');
    expect($sofia)->toMatchArray([
        'description' => 'Cuota septiembre 2026',
        'group' => 'Sub-8',
        'base_amount' => 150000,
        'final_amount' => 60000,
        'adjustments' => [
            ['type' => 'beca', 'label' => 'Beca 50 %', 'amount' => -75000],
            ['type' => 'hermanos', 'label' => 'Hermanos (2º hijo) −20 %', 'amount' => -15000],
        ],
    ]);
});

it('estado de cuenta de un hijo', function () {
    accountApi($this->user, "students/{$this->sofia->id}/account")
        ->assertOk()
        ->assertJsonCount(1, 'data.students')
        ->assertJsonPath('data.balance', 120000)
        ->assertJsonCount(2, 'data.charges');
});

it('los anulados no vienen ni suman', function () {
    app(VoidCharge::class)->handle(Charge::query()->where('student_id', $this->mateo->id)->first(), 'Error', $this->user);

    accountApi($this->user, 'account')
        ->assertJsonPath('data.balance', 270000)
        ->assertJsonCount(3, 'data.charges');
});

it('un alumno ajeno responde 404 y no se mezclan organizaciones', function () {
    $other = memberOf($this->jakare);

    accountApi($other, "students/{$this->mateo->id}/account")->assertNotFound();
    accountApi($other, 'account')->assertOk()->assertJsonPath('data.balance', 0)->assertJsonCount(0, 'data.charges');
    accountApi($this->user, 'account', 'ajena')->assertForbidden();
});
