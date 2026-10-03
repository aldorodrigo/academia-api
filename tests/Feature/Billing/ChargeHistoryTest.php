<?php

use App\Actions\Billing\GenerateSeasonCharges;
use App\Actions\Billing\RegisterPayment;
use App\Actions\Billing\VoidCharge;
use App\Enums\OrganizationRole;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Charges\Pages\ManageCharges;
use App\Filament\Support\ChargeHistory;
use App\Models\Charge;
use App\Models\Enrollment;
use App\Models\Family;
use App\Models\FeeConcept;
use App\Models\Group;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Season;
use App\Models\Student;
use App\Models\Tariff;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'America/Asuncion'));
    $this->org = Organization::factory()->create(['slug' => 'jakare']);
    app(CurrentOrganization::class)->set($this->org);
    $this->admin = memberOf($this->org, ['name' => 'Rosa Tesorera']);
    app(RoleAssigner::class)->assign($this->org, $this->admin, OrganizationRole::Admin);
    $this->actingAs($this->admin);

    $season = Season::factory()->for($this->org)->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-12-31', 'fee_frequency' => 'mensual']);
    $program = Program::factory()->for($this->org)->create();
    $group = Group::factory()->for($program)->create(['organization_id' => $this->org->id]);
    $this->family = Family::factory()->for($this->org)->create();
    $student = Student::factory()->for($this->org)->create(['family_id' => $this->family->id]);
    Enrollment::factory()->create(['student_id' => $student->id, 'group_id' => $group->id, 'season_id' => $season->id, 'enrolled_on' => '2026-09-01']);
    Tariff::factory()->create(['fee_concept_id' => FeeConcept::monthlyFee($this->org)->id, 'season_id' => $season->id, 'amount' => 150000, 'valid_from' => '2026-09-01']);

    app(GenerateSeasonCharges::class)->handle($this->org, CarbonImmutable::parse('2026-09-28'));
    $this->charge = Charge::query()->sole();
});

it('muestra la emisión, el pago parcial, la anulación y la cuota que la reemplaza', function () {
    app(RegisterPayment::class)->handle($this->family, MoneyAccount::query()->first(), 50000, PaymentMethod::Cash, CarbonImmutable::parse('2026-09-28'), by: $this->admin);
    app(VoidCharge::class)->handle($this->charge->fresh(), 'Beca aprobada', $this->admin, reissue: true);

    expect(ChargeHistory::for($this->charge->fresh())->map(fn (array $entry) => [$entry['text'], $entry['by']])->all())->toBe([
        ['Emitida por ₲ 150.000', 'Rosa Tesorera'],
        ['Pago, recibo N° 000001 · ₲ 50.000', 'Rosa Tesorera'],
        ['Anulada: Beca aprobada', 'Rosa Tesorera'],
        ['Reemplazada por una nueva cuota de ₲ 150.000', 'Rosa Tesorera'],
    ]);

    $new = Charge::query()->whereNull('voided_at')->sole();
    expect(ChargeHistory::for($new)->first()['text'])->toBe('Emitida por ₲ 150.000 (reemplaza a la cuota anulada el 28/09/2026)');
});

it('está en la lista de cargos del panel', function () {
    filament()->setTenant($this->org);

    Livewire::test(ManageCharges::class)
        ->assertTableActionVisible('history', $this->charge)
        ->assertTableActionHasLabel('history', 'Historial');
});
