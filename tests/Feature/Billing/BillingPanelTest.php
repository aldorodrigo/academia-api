<?php

use App\Enums\OrganizationRole;
use App\Filament\Resources\Charges\Pages\ManageCharges;
use App\Filament\Resources\DiscountRules\Pages\ManageDiscountRules;
use App\Filament\Resources\MoneyAccounts\Pages\ListMoneyAccounts;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Scholarships\Pages\ManageScholarships;
use App\Models\Charge;
use App\Models\Enrollment;
use App\Models\Family;
use App\Models\FeeConcept;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\Payment;
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

    $this->season = Season::factory()->for($this->jakare)->create(['name' => '2026', 'starts_on' => '2026-02-01', 'ends_on' => '2026-11-30']);
    Tariff::factory()->create(['fee_concept_id' => FeeConcept::monthlyFee($this->jakare)->id, 'season_id' => $this->season->id, 'valid_from' => '2026-02-01']);
    $this->enrollment = Enrollment::factory()->create(['student_id' => Student::factory()->for($this->jakare)->create()->id, 'season_id' => $this->season->id]);
});

it('las páginas de finanzas cargan', function (string $path) {
    Charge::factory()->create(['student_id' => $this->enrollment->student_id]);

    $this->get("/admin/jakare/{$path}")->assertOk();
})->with(['cargos', 'tarifas', 'descuentos', 'becas', 'profile']);

it('generar cuotas propone la temporada vigente, emite hasta hoy o toda la temporada y es idempotente', function () {
    $this->season->update(['fee_frequency' => 'mensual']);

    Livewire::test(ManageCharges::class)
        ->mountAction('generate')
        ->assertSet('mountedActions.0.data.season_id', $this->season->id)
        ->assertSet('mountedActions.0.data.scope', 'today')
        ->callMountedAction()
        ->assertNotified('Cuotas generadas: 1.');

    Livewire::test(ManageCharges::class)
        ->callAction('generate', data: ['season_id' => $this->season->id, 'scope' => 'today'])
        ->assertNotified('Cuotas generadas: 0.');

    // Septiembre ya estaba; toda la temporada suma octubre y noviembre.
    Livewire::test(ManageCharges::class)
        ->callAction('generate', data: ['season_id' => $this->season->id, 'scope' => 'season'])
        ->assertNotified('Cuotas generadas: 2.');

    expect(Charge::query()->count())->toBe(3);
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

describe('cobros', function () {
    beforeEach(function () {
        $this->family = Family::factory()->for($this->jakare)->create(['name' => 'Familia Benítez']);
        $this->enrollment->student->update(['family_id' => $this->family->id]);
        issueMonth($this->jakare, '2026-08');
        issueMonth($this->jakare, '2026-09');
    });

    it('las páginas de cobros cargan', function () {
        $this->get('/admin/jakare/pagos')->assertOk();
        $this->get('/admin/jakare/cuentas')->assertOk();
        $this->get('/admin/jakare/cuentas/'.MoneyAccount::query()->first()->id)->assertOk();
    });

    it('cuenta nueva con saldo inicial como primer movimiento', function () {
        Livewire::test(ListMoneyAccounts::class)
            ->callAction('create', data: ['name' => 'Banco Itaú', 'type' => 'banco', 'opening_balance' => 500000])
            ->assertHasNoActionErrors();

        $bank = MoneyAccount::query()->where('name', 'Banco Itaú')->sole();
        expect($bank->balance())->toBe(500000)
            ->and($bank->entries()->sole()->description)->toBe('Saldo inicial');
    });

    it('registrar pago preselecciona del más viejo al más nuevo y deja saldo a favor', function () {
        $page = Livewire::test(ManagePayments::class)
            ->mountAction('register')
            ->set('mountedActions.0.data.family_id', $this->family->id)
            ->set('mountedActions.0.data.amount', 200000);

        $august = Charge::query()->whereDate('period', '2026-08-01')->sole();
        $september = Charge::query()->whereDate('period', '2026-09-01')->sole();
        expect(array_map('intval', $page->get('mountedActions.0.data.charge_ids')))->toBe([$august->id, $september->id]);

        $page->callMountedAction()->assertHasNoActionErrors();

        $payment = Payment::query()->sole();
        expect($payment->allocations->pluck('amount')->all())->toBe([150000, 50000])
            ->and($august->fresh()->status()->value)->toBe('pagado')
            ->and($september->fresh()->pendingAmount())->toBe(100000);
    });

    it('si el tesorero destilda un cargo, lo que sobra queda de saldo a favor', function () {
        $september = Charge::query()->whereDate('period', '2026-09-01')->sole();

        Livewire::test(ManagePayments::class)
            ->mountAction('register')
            ->set('mountedActions.0.data.family_id', $this->family->id)
            ->set('mountedActions.0.data.amount', 200000)
            ->set('mountedActions.0.data.charge_ids', [$september->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        expect(Payment::query()->sole()->credit())->toBe(50000)
            ->and($september->fresh()->status()->value)->toBe('pagado');
    });

    it('anular un pago desde la lista', function () {
        Livewire::test(ManagePayments::class)
            ->mountAction('register')
            ->set('mountedActions.0.data.family_id', $this->family->id)
            ->set('mountedActions.0.data.amount', 150000)
            ->callMountedAction();
        $payment = Payment::query()->sole();

        Livewire::test(ManagePayments::class)
            ->callTableAction('void', $payment, data: ['reason' => 'Error'])
            ->assertNotified('Recibo N° 000001 anulado.');

        expect($payment->fresh()->isVoided())->toBeTrue()
            ->and(Charge::query()->whereDate('period', '2026-08-01')->sole()->pendingAmount())->toBe(150000);
    });
});
