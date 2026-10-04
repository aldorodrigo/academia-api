<?php

use App\Actions\Billing\RegisterPayment;
use App\Actions\Billing\VoidPayment;
use App\Actions\Treasury\ExpenseLedger;
use App\Actions\Treasury\TransferFunds;
use App\Enums\ExpenseStatus;
use App\Enums\OrganizationRole;
use App\Enums\PaymentMethod;
use App\Models\Enrollment;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Family;
use App\Models\FeeConcept;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\LedgerEntry;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\Program;
use App\Models\RecurringExpense;
use App\Models\Role;
use App\Models\Season;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\Tariff;
use App\Models\User;
use App\Reports\BalanceReport;
use App\Reports\DelinquentsReport;
use App\Reports\FamilyBalancesReport;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->travelTo('2026-09-15 12:00:00');
    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    app(CurrentOrganization::class)->set($this->jakare);
    $this->user = memberOf($this->jakare);

    $this->cash = MoneyAccount::query()->where('name', 'Caja')->sole();
    $this->bank = MoneyAccount::factory()->for($this->jakare)->create(['name' => 'Banco Itaú']);
    $this->rent = ExpenseCategory::query()->where('name', 'Alquiler de cancha')->sole();
    $this->referees = ExpenseCategory::query()->where('name', 'Árbitros')->sole();
    $this->supplier = Supplier::query()->create(['name' => 'Complejo Luque']);
    LedgerEntry::query()->create(['money_account_id' => $this->bank->id, 'occurred_on' => '2026-08-01', 'amount' => 2000000, 'description' => 'Saldo inicial']);
});

function expense(int $amount, ?ExpenseCategory $category = null, string $on = '2026-09-10'): Expense
{
    return app(ExpenseLedger::class)->register(
        ['expense_category_id' => ($category ?? test()->referees)->id, 'description' => 'Árbitros fecha 5', 'amount' => $amount],
        test()->bank, CarbonImmutable::parse($on), test()->user,
    );
}

describe('gastos', function () {
    it('pagado sale de la cuenta; anular hace el contra-movimiento', function () {
        $expense = expense(250000);

        expect($expense->status)->toBe(ExpenseStatus::Paid)
            ->and($this->bank->balance())->toBe(1750000)
            ->and(fn () => $expense->update(['amount' => 1]))->toThrow(LogicException::class)
            ->and(fn () => $expense->delete())->toThrow(LogicException::class);

        app(ExpenseLedger::class)->void($expense, 'Cargado dos veces', $this->user);

        expect($expense->fresh()->status)->toBe(ExpenseStatus::Voided)
            ->and($this->bank->balance())->toBe(2000000);
    });

    it('recurrente: un pendiente por mes, respeta vigencia y pagar lo descuenta', function () {
        RecurringExpense::query()->create([
            'expense_category_id' => $this->rent->id, 'supplier_id' => $this->supplier->id, 'money_account_id' => $this->bank->id,
            'description' => 'Alquiler de cancha', 'amount' => 1200000, 'day_of_month' => 5, 'starts_on' => '2026-09-01', 'ends_on' => '2026-10-31',
        ]);
        $ledger = app(ExpenseLedger::class);

        expect($ledger->generateRecurring($this->jakare, CarbonImmutable::parse('2026-09-01')))->toBe(['created' => 1, 'existing' => 0])
            ->and($ledger->generateRecurring($this->jakare, CarbonImmutable::parse('2026-09-01')))->toBe(['created' => 0, 'existing' => 1])
            ->and($ledger->generateRecurring($this->jakare, CarbonImmutable::parse('2026-11-01'))['created'])->toBe(0);

        $pending = Expense::query()->sole();
        expect($pending->status)->toBe(ExpenseStatus::Pending)
            ->and($pending->due_on->toDateString())->toBe('2026-09-05')
            ->and($pending->description)->toBe('Alquiler de cancha septiembre 2026')
            ->and($this->bank->balance())->toBe(2000000);

        $ledger->pay($pending, $this->bank, CarbonImmutable::parse('2026-09-06'), $this->user);
        expect($this->bank->balance())->toBe(800000)
            ->and(fn () => $ledger->pay($pending->fresh(), $this->bank, CarbonImmutable::parse('2026-09-06')))->toThrow(ValidationException::class);
    });

    it('el comando genera los recurrentes', function () {
        RecurringExpense::query()->create(['expense_category_id' => $this->rent->id, 'description' => 'Alquiler', 'amount' => 1, 'day_of_month' => 5, 'starts_on' => '2026-01-01']);

        $this->artisan('expenses:generate', ['--organization' => 'jakare', '--period' => '2026-09'])
            ->expectsOutputToContain('1 gastos generados')->assertSuccessful();
    });
});

describe('transferencias', function () {
    it('dos movimientos, el total no cambia y anular revierte los dos', function () {
        $transfer = app(TransferFunds::class)->handle($this->bank, $this->cash, 300000, CarbonImmutable::parse('2026-09-12'), 'Cambio', $this->user);

        expect($this->bank->balance())->toBe(1700000)
            ->and($this->cash->balance())->toBe(300000)
            ->and($transfer->ledgerEntries()->count())->toBe(2)
            ->and(fn () => app(TransferFunds::class)->handle($this->bank, $this->bank, 1, now()->toImmutable()))->toThrow(ValidationException::class);

        app(TransferFunds::class)->void($transfer, 'Error', $this->user);
        expect($this->bank->balance())->toBe(2000000)->and($this->cash->balance())->toBe(0);
    });
});

describe('informes', function () {
    beforeEach(function () {
        $season = Season::factory()->for($this->jakare)->create(['starts_on' => '2026-02-01', 'ends_on' => '2026-11-30']);
        $group = Group::factory()->for(Program::factory()->for($this->jakare))->create(['organization_id' => $this->jakare->id]);
        Tariff::factory()->create(['fee_concept_id' => FeeConcept::monthlyFee($this->jakare)->id, 'season_id' => $season->id, 'amount' => 150000, 'valid_from' => '2026-02-01']);

        $this->benitez = Family::factory()->for($this->jakare)->create(['name' => 'Familia Benítez']);
        $this->ortiz = Family::factory()->for($this->jakare)->create(['name' => 'Familia Ortiz']);
        Guardian::factory()->for($this->jakare)->create(['first_name' => 'Rosa', 'last_name' => 'Ortiz', 'phone' => '0981 222 333', 'family_id' => $this->ortiz->id]);
        foreach ([$this->benitez, $this->ortiz] as $family) {
            $student = Student::factory()->for($this->jakare)->create(['family_id' => $family->id]);
            Enrollment::factory()->create(['student_id' => $student->id, 'group_id' => $group->id, 'season_id' => $season->id]);
        }
        foreach (['2026-07-01', '2026-08-01', '2026-09-01'] as $period) {
            issueMonth($this->jakare, substr($period, 0, 7));
        }

        // Benítez paga todo y deja ₲ 50.000 a favor; Ortiz no paga.
        app(RegisterPayment::class)->handle($this->benitez, $this->cash, 500000, PaymentMethod::Cash, CarbonImmutable::parse('2026-09-12'));
        expense(250000);
        app(TransferFunds::class)->handle($this->cash, $this->bank, 100000, CarbonImmutable::parse('2026-09-13'));
    });

    it('balance del mes: cierra con el libro mayor, sin transferencias', function () {
        $data = (new BalanceReport($this->jakare, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30')))->data();

        expect($data['opening_balance'])->toBe(2000000)
            ->and($data['income'])->toBe(['total' => 500000, 'lines' => [['label' => 'Cuota', 'amount' => 450000], ['label' => 'Saldo a favor', 'amount' => 50000]]])
            ->and($data['expenses'])->toBe(['total' => 250000, 'lines' => [['label' => 'Árbitros', 'amount' => 250000]]])
            ->and($data['closing_balance'])->toBe(2250000)
            ->and($data['opening_balance'] + $data['income']['total'] - $data['expenses']['total'] + $data['other'])->toBe($data['closing_balance'])
            ->and(collect($data['accounts'])->pluck('balance', 'name')->all())->toBe(['Banco Itaú' => 1850000, 'Caja' => 400000]);
    });

    it('un jugador sin familia también aparece en saldos y morosos', function () {
        $alone = Student::factory()->for($this->jakare)->create(['first_name' => 'Lucas', 'last_name' => 'Ramírez']);
        // Se inscribe con la temporada ya cobrando: sus cuotas desde agosto salen al inscribirlo.
        Enrollment::factory()->create(['student_id' => $alone->id, 'season_id' => Season::query()->orderBy('starts_on')->first()->id, 'enrolled_on' => '2026-08-01']);
        issueMonth($this->jakare, '2026-08');

        $delinquents = (new DelinquentsReport($this->jakare))->data();
        $balances = (new FamilyBalancesReport($this->jakare))->data();

        expect(collect($delinquents['families'])->pluck('family'))->toContain('Lucas Ramírez')
            ->and(collect($balances['families'])->firstWhere('family', 'Lucas Ramírez')['pending'])->toBe(300000);
    });

    it('un pago anulado en otro mes resta en ese mes', function () {
        $this->travelTo('2026-10-02 12:00:00');
        app(VoidPayment::class)->handle($this->benitez->payments()->sole(), 'Rechazado', $this->user);

        $october = (new BalanceReport($this->jakare, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31')))->data();
        expect($october['income']['total'])->toBe(-500000)
            ->and($october['opening_balance'] + $october['income']['total'] - $october['expenses']['total'] + $october['other'])->toBe($october['closing_balance']);
    });

    it('saldos por familia y morosos por API, con permiso', function () {
        $treasurer = memberOf($this->jakare);
        app(RoleAssigner::class)->assign($this->jakare, $treasurer, OrganizationRole::Treasurer, endsOn: now()->addYear());
        Permission::findOrCreate('View:Reports');
        Role::query()->where('organization_id', $this->jakare->id)->where('name', 'tesorero')->sole()->givePermissionTo('View:Reports');
        $api = fn (User $user, string $uri) => $this->actingAs($user, 'sanctum')->getJson("/api/v1/{$uri}", ['X-Organization' => 'jakare']);

        $api($treasurer, 'organization')->assertJsonPath('data.membership.permissions', ['view_reports', 'review_payment_reports', 'collect_payments']);

        $api($treasurer, 'reports/balances')
            ->assertOk()
            ->assertJsonPath('data.totals.pending', 450000)
            ->assertJsonPath('data.totals.credit', 50000)
            ->assertJsonPath('data.families.0.family', 'Familia Ortiz')
            ->assertJsonPath('data.families.1.credit', 50000);

        $delinquents = $api($treasurer, 'reports/delinquents?min_months=2')
            ->assertOk()
            ->assertJsonPath('data.total', 450000)
            ->assertJsonPath('data.families.0.months_overdue', 3)
            ->assertJsonPath('data.families.0.oldest_due_on', '2026-07-10')
            ->assertJsonPath('data.families.0.contact.phone', '0981 222 333');
        $api($treasurer, 'reports/delinquents?min_months=4')->assertJsonCount(0, 'data.families');

        $balance = $api($treasurer, 'reports/balance?from=2026-09-01&to=2026-09-30')
            ->assertOk()->assertJsonPath('data.closing_balance', 2250000);

        // Descargas firmadas: PDF y Excel; vencidas → 403.
        $this->get($balance->json('data.pdf_url'))->assertOk()->assertHeader('content-type', 'application/pdf');
        $xlsx = $this->get($delinquents->json('data.xlsx_url'))->assertOk();
        expect($xlsx->headers->get('content-disposition'))->toContain('.xlsx');
        $this->travel(31)->minutes();
        $this->get($balance->json('data.pdf_url'))->assertForbidden();

        // Sin permiso (tutor u otro miembro): 403 y sin permiso en la organización.
        $member = memberOf($this->jakare);
        $api($member, 'reports/balances')->assertForbidden();
        $api($member, 'organization')->assertJsonPath('data.membership.permissions', []);
    });
});
