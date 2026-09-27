<?php

use App\Enums\OrganizationRole;
use App\Filament\Resources\Charges\Pages\ManageCharges;
use App\Filament\Resources\DiscountRules\Pages\ManageDiscountRules;
use App\Filament\Resources\Scholarships\Pages\ManageScholarships;
use App\Models\Charge;
use App\Models\Enrollment;
use App\Models\FeeConcept;
use App\Models\Organization;
use App\Models\Scholarship;
use App\Models\Season;
use App\Models\Student;
use App\Models\Tariff;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-05 12:00:00');
    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    $this->admin = memberOf($this->jakare);
    app(RoleAssigner::class)->assign($this->jakare, $this->admin, OrganizationRole::Admin);
    $this->actingAs($this->admin);
    filament()->setTenant($this->jakare);
    app(CurrentOrganization::class)->set($this->jakare);

    $this->season = Season::factory()->for($this->jakare)->create(['name' => '2026', 'starts_on' => '2026-02-01', 'ends_on' => '2026-11-30', 'is_current' => true]);
    Tariff::factory()->create(['fee_concept_id' => FeeConcept::monthlyFee($this->jakare)->id, 'season_id' => $this->season->id, 'valid_from' => '2026-02-01']);
    $this->enrollment = Enrollment::factory()->create(['student_id' => Student::factory()->for($this->jakare)->create()->id, 'season_id' => $this->season->id]);
});

it('las páginas de finanzas cargan', function (string $path) {
    Charge::factory()->create(['student_id' => $this->enrollment->student_id]);

    $this->get("/admin/jakare/{$path}")->assertOk();
})->with(['cargos', 'tarifas', 'descuentos', 'becas', 'profile']);

it('generar cuotas propone el mes actual y es idempotente', function () {
    Livewire::test(ManageCharges::class)
        ->mountAction('generate')
        ->assertSet('mountedActions.0.data.period', '2026-09-01')
        ->callMountedAction()
        ->assertNotified('Cuotas generadas: 1.');

    Livewire::test(ManageCharges::class)
        ->callAction('generate', data: ['period' => '2026-09-01'])
        ->assertNotified('Cuotas generadas: 0.');

    expect(Charge::query()->count())->toBe(1);
});

it('anular pide motivo', function () {
    $charge = Charge::factory()->create(['student_id' => $this->enrollment->student_id]);

    Livewire::test(ManageCharges::class)
        ->callTableAction('void', $charge, data: ['reason' => ''])
        ->assertHasTableActionErrors(['reason']);

    Livewire::test(ManageCharges::class)
        ->callTableAction('void', $charge, data: ['reason' => 'Cargado por error'])
        ->assertNotified('Cargo anulado.');

    expect($charge->fresh()->void_reason)->toBe('Cargado por error');
});

it('nueva beca queda pendiente y el admin la aprueba', function () {
    Livewire::test(ManageScholarships::class)
        ->callAction('request', data: ['enrollment_id' => $this->enrollment->id, 'percent' => 50, 'reason' => 'Situación económica', 'valid_from' => '2026-09-01'])
        ->assertNotified();

    $scholarship = Scholarship::query()->sole();
    expect($scholarship->status->value)->toBe('pendiente');

    Livewire::test(ManageScholarships::class)
        ->callTableAction('approve', $scholarship, data: ['note' => null])
        ->assertNotified('Beca: Aprobar.');

    expect($scholarship->fresh()->status->value)->toBe('aprobada')
        ->and($this->enrollment->fresh()->status->value)->toBe('becado');
});

it('descuentos y becas nuevos cuentan para la cuota del mes en curso', function () {
    Livewire::test(ManageDiscountRules::class)
        ->mountAction('create')
        ->assertSet('mountedActions.0.data.valid_from', '2026-02-01');

    Livewire::test(ManageScholarships::class)
        ->mountAction('request')
        ->assertSet('mountedActions.0.data.valid_from', '2026-09-01');
});
