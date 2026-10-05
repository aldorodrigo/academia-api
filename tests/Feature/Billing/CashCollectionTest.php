<?php

use App\Actions\Billing\CashCollectionAccess;
use App\Actions\Billing\VoidPayment;
use App\Actions\Organizations\RegisterOrganization;
use App\Actions\Treasury\TransferFunds;
use App\Enums\CashDepositStatus;
use App\Enums\OrganizationRole;
use App\Enums\PaymentMethod;
use App\Enums\PaymentReportStatus;
use App\Filament\Resources\CashDeposits\CashDepositResource;
use App\Filament\Resources\CashDeposits\Pages\ManageCashDeposits;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\MoneyAccounts\Pages\ListMoneyAccounts;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Http\Controllers\ReceiptController;
use App\Models\CashDeposit;
use App\Models\Charge;
use App\Models\Enrollment;
use App\Models\Family;
use App\Models\FeeConcept;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\Program;
use App\Models\Role;
use App\Models\Season;
use App\Models\Student;
use App\Models\Tariff;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\CashDepositReported;
use App\Notifications\CashDepositReviewed;
use App\Notifications\PaymentReceived;
use App\Notifications\PaymentReported;
use App\Notifications\PaymentReportReviewed;
use App\Support\Money;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->travelTo('2026-09-03 12:00:00');
    Notification::fake();

    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    app(CurrentOrganization::class)->set($this->jakare);

    $season = Season::factory()->for($this->jakare)->create(['name' => '2026', 'starts_on' => '2026-02-01', 'ends_on' => '2026-11-30']);
    $program = Program::factory()->for($this->jakare)->create(['name' => 'Fútbol']);
    $this->sub10 = Group::factory()->for($program)->create(['name' => 'Sub-10', 'organization_id' => $this->jakare->id]);
    $this->sub8 = Group::factory()->for($program)->create(['name' => 'Sub-8', 'organization_id' => $this->jakare->id]);
    Tariff::factory()->create(['fee_concept_id' => FeeConcept::monthlyFee($this->jakare)->id, 'season_id' => $season->id, 'amount' => 150000, 'valid_from' => '2026-02-01']);

    // Familia Benítez: Mateo en Sub-10 (grupo del técnico) y Sofía en Sub-8.
    $this->tutor = memberOf($this->jakare, ['name' => 'Ana Benítez']);
    $this->family = Family::factory()->for($this->jakare)->create(['name' => 'Familia Benítez']);
    $this->guardian = Guardian::factory()->for($this->jakare)->create(['user_id' => $this->tutor->id, 'family_id' => $this->family->id]);
    $this->mateo = Student::factory()->for($this->jakare)->create(['first_name' => 'Mateo', 'last_name' => 'Benítez', 'birth_date' => '2016-03-14', 'family_id' => $this->family->id]);
    $this->sofia = Student::factory()->for($this->jakare)->create(['first_name' => 'Sofía', 'last_name' => 'Benítez', 'birth_date' => '2018-07-02', 'family_id' => $this->family->id]);
    $this->guardian->students()->attach([$this->mateo->id, $this->sofia->id]);
    Enrollment::factory()->create(['student_id' => $this->mateo->id, 'group_id' => $this->sub10->id, 'season_id' => $season->id]);
    Enrollment::factory()->create(['student_id' => $this->sofia->id, 'group_id' => $this->sub8->id, 'season_id' => $season->id]);

    // Otro alumno de Sub-8, que el técnico de Sub-10 no cobra.
    $this->lucia = Student::factory()->for($this->jakare)->create(['first_name' => 'Lucía', 'last_name' => 'Núñez', 'birth_date' => '2018-01-02']);
    Enrollment::factory()->create(['student_id' => $this->lucia->id, 'group_id' => $this->sub8->id, 'season_id' => $season->id]);

    issueMonth($this->jakare, '2026-08');
    issueMonth($this->jakare, '2026-09');

    $this->cash = MoneyAccount::query()->where('name', 'Caja')->sole(); // La crea la organización.
    $this->bank = MoneyAccount::factory()->for($this->jakare)->create(['name' => 'Banco Itaú', 'type' => 'banco']);

    $this->coach = memberOf($this->jakare, ['name' => 'Juan Pérez']);
    app(RoleAssigner::class)->assign($this->jakare, $this->coach, OrganizationRole::Instructor);
    $this->sub10->instructors()->attach($this->coach);

    $this->treasurer = memberOf($this->jakare, ['name' => 'Laura Gómez']);
    app(RoleAssigner::class)->assign($this->jakare, $this->treasurer, OrganizationRole::Treasurer, endsOn: now()->addYear());
});

function cashCharge(Student $student, string $period): Charge
{
    return Charge::query()->where('student_id', $student->id)->whereDate('period', "{$period}-01")->sole();
}

function cashApi(User $user, string $method, string $uri, array $data = [])
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return test()->actingAs($user, 'sanctum')->json($method, "/api/v1/{$uri}", $data, ['X-Organization' => 'jakare']);
}

function collectFrom(User $user, array $data = [])
{
    return cashApi($user, 'POST', 'collections', [
        'student_id' => test()->mateo->id,
        'amount' => 300000,
        'charge_ids' => [cashCharge(test()->mateo, '2026-08')->id, cashCharge(test()->sofia, '2026-08')->id],
        'guardian_id' => test()->guardian->id,
        ...$data,
    ]);
}

describe('permiso', function () {
    it('el técnico, el tesorero y el admin cobran; el tutor no', function () {
        $admin = memberOf($this->jakare);
        app(RoleAssigner::class)->assign($this->jakare, $admin, OrganizationRole::Admin);
        $permissions = fn (User $user) => cashApi($user, 'GET', 'organization')->json('data.membership.permissions');

        expect($permissions($this->coach))->toContain('collect_payments')
            ->and($permissions($this->treasurer))->toContain('collect_payments')
            ->and($permissions($admin))->toContain('collect_payments')
            ->and($permissions($this->tutor))->not->toContain('collect_payments');

        cashApi($this->tutor, 'GET', 'collections/students')->assertForbidden()
            ->assertJsonPath('message', 'No tenés permiso para cobrar desde la app.');
        cashApi($this->tutor, 'GET', 'me/cash-box')->assertForbidden();
    });

    it('sacárselo al rol técnico corta el cobro', function () {
        Role::query()->where('organization_id', $this->jakare->id)->where('name', 'instructor')->sole()
            ->revokePermissionTo('Collect:Payments');

        cashApi($this->coach->fresh(), 'GET', 'collections/students')->assertForbidden();
        collectFrom($this->coach->fresh())->assertForbidden();
    });
});

describe('cobrar', function () {
    it('el técnico ve los alumnos de sus grupos con lo que debe la familia', function () {
        cashApi($this->coach, 'GET', 'collections/students')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Mateo Benítez')
            ->assertJsonPath('data.0.groups', ['Sub-10'])
            ->assertJsonPath('data.0.family', 'Familia Benítez')
            // Agosto y septiembre de los dos hermanos.
            ->assertJsonPath('data.0.due_now', 600000)
            ->assertJsonPath('data.0.overdue', 300000);

        // El tesorero ve a todos; la búsqueda filtra.
        cashApi($this->treasurer, 'GET', 'collections/students')->assertJsonCount(3, 'data');
        cashApi($this->treasurer, 'GET', 'collections/students?search=Núñez')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Lucía Núñez')
            ->assertJsonPath('data.0.family', null);
    });

    it('muestra las cuotas de toda la familia, de la más vieja a la más nueva', function () {
        PaymentReport::query()->create([
            'organization_id' => $this->jakare->id, 'family_id' => $this->family->id, 'user_id' => $this->tutor->id,
            'amount' => 150000, 'paid_on' => '2026-09-02', 'charge_ids' => [cashCharge($this->sofia, '2026-09')->id],
            'proof_path' => 'x.jpg', 'proof_name' => 'x.jpg',
        ]);

        $response = cashApi($this->coach, 'GET', "collections/students/{$this->mateo->id}")
            ->assertOk()
            ->assertJsonPath('data.student.full_name', 'Mateo Benítez')
            ->assertJsonPath('data.family.name', 'Familia Benítez')
            ->assertJsonPath('data.family.students', ['Mateo', 'Sofía'])
            ->assertJsonPath('data.guardians.0.id', $this->guardian->id)
            ->assertJsonPath('data.credit', 0)
            ->assertJsonPath('data.cash_box', null)
            ->assertJsonCount(4, 'data.charges')
            ->assertJsonPath('data.charges.0.description', 'Cuota agosto 2026')
            ->assertJsonPath('data.charges.0.settle_amount', 150000)
            ->assertJsonPath('data.charges.0.early_payment', null);

        $sofiaSeptember = collect($response->json('data.charges'))->firstWhere('id', cashCharge($this->sofia, '2026-09')->id);
        expect($sofiaSeptember['under_review'])->toBeTrue()
            ->and($sofiaSeptember['student']['first_name'])->toBe('Sofía');

        // Lucía no está en sus grupos.
        cashApi($this->coach, 'GET', "collections/students/{$this->lucia->id}")->assertNotFound();
    });

    it('cobra en efectivo: entra en su caja, imputa lo elegido, recibo y aviso a la familia', function () {
        $response = collectFrom($this->coach)
            ->assertCreated()
            ->assertJsonPath('data.payment.receipt_number', '000001')
            ->assertJsonPath('data.payment.method', 'efectivo')
            ->assertJsonPath('data.payment.amount', 300000)
            ->assertJsonPath('data.applied', 300000)
            ->assertJsonPath('data.credit', 0)
            ->assertJsonPath('data.cash_box.name', 'Caja de Juan Pérez')
            ->assertJsonPath('data.cash_box.balance', 300000)
            ->assertJsonPath('data.message', 'Cobrado ₲ 300.000. Recibo N° 000001.');

        $payment = Payment::query()->sole();
        $box = MoneyAccount::cashBoxOf($this->coach, $this->jakare);
        expect($payment->method)->toBe(PaymentMethod::Cash)
            ->and($payment->money_account_id)->toBe($box->id)
            ->and($payment->guardian_id)->toBe($this->guardian->id)
            ->and($payment->created_by)->toBe($this->coach->id)
            ->and($payment->received_on->toDateString())->toBe('2026-09-03')
            ->and($payment->allocations->pluck('charge_id')->all())->toBe([cashCharge($this->mateo, '2026-08')->id, cashCharge($this->sofia, '2026-08')->id])
            ->and($box->user_id)->toBe($this->coach->id)
            ->and($box->type->value)->toBe('caja')
            ->and($box->balance())->toBe(300000)
            ->and($this->cash->balance())->toBe(0)
            ->and(cashCharge($this->mateo, '2026-08')->fresh()->pendingAmount())->toBe(0);
        expect($response->json('data.payment.receipt_url'))->toContain('/recibos/'.$payment->id);

        Notification::assertSentTo($this->tutor, PaymentReceived::class, fn (PaymentReceived $n) => $n->body === 'Recibimos tu pago de ₲ 300.000 en efectivo (cobró Juan Pérez). Recibo N° 000001.');
        Notification::assertNotSentTo($this->coach, PaymentReceived::class);
    });

    it('cobro parcial y pago de más', function () {
        collectFrom($this->coach, ['amount' => 100000])->assertCreated()->assertJsonPath('data.applied', 100000);
        expect(cashCharge($this->mateo, '2026-08')->fresh()->pendingAmount())->toBe(50000);

        // Sin cuotas elegidas: de la más vieja a la más nueva; lo que sobra queda a favor.
        collectFrom($this->coach, ['amount' => 600000, 'charge_ids' => [], 'request_id' => 'cobro-dos'])
            ->assertCreated()
            ->assertJsonPath('data.applied', 500000)
            ->assertJsonPath('data.credit', 100000)
            ->assertJsonPath('data.cash_box.balance', 700000);
        expect($this->family->fresh()->credit())->toBe(100000);
    });

    it('un reintento con el mismo request_id no cobra dos veces', function () {
        collectFrom($this->coach, ['request_id' => 'abc12345'])->assertCreated();
        collectFrom($this->coach, ['request_id' => 'abc12345'])->assertCreated()->assertJsonPath('data.payment.receipt_number', '000001');

        expect(Payment::query()->count())->toBe(1);
        Notification::assertSentToTimes($this->tutor, PaymentReceived::class, 1);
    });

    it('no cobra cuotas ajenas, a tutores de otra familia ni con la caja cerrada', function () {
        $other = Charge::query()->where('student_id', $this->lucia->id)->first();
        collectFrom($this->coach, ['charge_ids' => [$other->id]])->assertUnprocessable()->assertJsonValidationErrors('charge_ids');

        $stranger = Guardian::factory()->for($this->jakare)->create(['family_id' => Family::factory()->for($this->jakare)->create()->id]);
        collectFrom($this->coach, ['guardian_id' => $stranger->id])->assertUnprocessable()->assertJsonValidationErrors('guardian_id');

        collectFrom($this->coach, ['student_id' => $this->lucia->id])->assertNotFound();

        MoneyAccount::ensureCashBoxOf($this->coach, $this->jakare)->update(['is_active' => false]);
        collectFrom($this->coach)->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'Tu caja está cerrada. Hablá con Laura Gómez para reabrirla.');
        expect(Payment::query()->count())->toBe(0);
    });
});

describe('mi caja', function () {
    it('saldo, movimientos y cuentas para depositar', function () {
        cashApi($this->coach, 'GET', 'me/cash-box')
            ->assertOk()
            ->assertJsonPath('data.id', null)
            ->assertJsonPath('data.balance', 0)
            ->assertJsonPath('data.deposit_accounts', [
                ['id' => $this->cash->id, 'name' => 'Caja', 'type' => 'caja'],
                ['id' => $this->bank->id, 'name' => 'Banco Itaú', 'type' => 'banco'],
            ]);

        collectFrom($this->coach)->assertCreated();

        cashApi($this->coach, 'GET', 'me/cash-box')
            ->assertOk()
            ->assertJsonPath('data.name', 'Caja de Juan Pérez')
            ->assertJsonPath('data.balance', 300000)
            ->assertJsonPath('data.available', 300000)
            ->assertJsonPath('data.movements.0.kind', 'cobro')
            ->assertJsonPath('data.movements.0.amount', 300000)
            ->assertJsonPath('data.movements.0.description', 'Recibo N° 000001 · Familia Benítez')
            // Su propia caja no es una cuenta para depositar.
            ->assertJsonCount(2, 'data.deposit_accounts');
    });

    it('depositar queda por confirmar, avisa a quienes validan y no supera lo disponible', function () {
        collectFrom($this->coach)->assertCreated();

        cashApi($this->coach, 'POST', 'me/cash-box/deposits', [
            'amount' => 200000, 'money_account_id' => $this->bank->id, 'deposited_on' => '2026-09-03', 'reference' => 'Boleta 5521',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pendiente')
            ->assertJsonPath('data.status_label', 'Por confirmar')
            ->assertJsonPath('data.money_account.name', 'Banco Itaú')
            ->assertJsonPath('data.holder.name', 'Juan Pérez');

        Notification::assertSentTo($this->treasurer, CashDepositReported::class, fn (CashDepositReported $n) => $n->body === 'Juan Pérez depositó ₲ 200.000 en Banco Itaú. Confirmalo cuando lo veas.');

        // La plata sigue en su caja hasta que se confirma.
        cashApi($this->coach, 'GET', 'me/cash-box')
            ->assertJsonPath('data.balance', 300000)
            ->assertJsonPath('data.pending_deposits', 200000)
            ->assertJsonPath('data.available', 100000)
            ->assertJsonPath('data.deposits.0.status', 'pendiente');
        expect($this->bank->balance())->toBe(0);

        cashApi($this->coach, 'POST', 'me/cash-box/deposits', ['amount' => 150000, 'money_account_id' => $this->bank->id, 'deposited_on' => '2026-09-03'])
            ->assertUnprocessable()->assertJsonPath('errors.amount.0', 'Tenés ₲ 100.000 para depositar.');

        $box = MoneyAccount::cashBoxOf($this->coach, $this->jakare);
        cashApi($this->coach, 'POST', 'me/cash-box/deposits', ['amount' => 1000, 'money_account_id' => $box->id, 'deposited_on' => '2026-09-03'])
            ->assertUnprocessable()->assertJsonValidationErrors('money_account_id');
        cashApi($this->coach, 'POST', 'me/cash-box/deposits', ['amount' => 1000, 'money_account_id' => $this->bank->id, 'deposited_on' => '2026-09-04'])
            ->assertUnprocessable()->assertJsonValidationErrors('deposited_on');
    });

    it('retira un depósito por confirmar', function () {
        collectFrom($this->coach)->assertCreated();
        $id = cashApi($this->coach, 'POST', 'me/cash-box/deposits', ['amount' => 300000, 'money_account_id' => $this->cash->id, 'deposited_on' => '2026-09-03'])->json('data.id');

        cashApi($this->treasurer, 'DELETE', "me/cash-box/deposits/{$id}")->assertNotFound();
        cashApi($this->coach, 'DELETE', "me/cash-box/deposits/{$id}")->assertNoContent();
        expect(CashDeposit::query()->count())->toBe(0);
        $this->assertSoftDeleted('cash_deposits', ['id' => $id]);

        // Retirado no cuenta para lo disponible, no se lista ni se confirma.
        cashApi($this->coach, 'GET', 'me/cash-box')
            ->assertJsonPath('data.pending_deposits', 0)
            ->assertJsonPath('data.available', 300000)
            ->assertJsonPath('data.deposits', []);
        cashApi($this->treasurer, 'GET', 'cash-boxes')->assertJsonPath('data.deposits', []);
        cashApi($this->treasurer, 'POST', "cash-deposits/{$id}/confirm")->assertNotFound();
    });

    it('sin cobros no puede depositar', function () {
        cashApi($this->coach, 'POST', 'me/cash-box/deposits', ['amount' => 1000, 'money_account_id' => $this->bank->id, 'deposited_on' => '2026-09-03'])
            ->assertUnprocessable()->assertJsonPath('errors.amount.0', 'No tenés efectivo para depositar.');
    });

    it('anular un pago en el panel lo saca de su caja', function () {
        collectFrom($this->coach)->assertCreated();

        app(VoidPayment::class)->handle(Payment::query()->sole(), 'Error de carga', $this->treasurer);

        cashApi($this->coach, 'GET', 'me/cash-box')
            ->assertJsonPath('data.balance', 0)
            ->assertJsonPath('data.movements.0.kind', 'anulacion');
    });
});

describe('quien valida', function () {
    beforeEach(function () {
        collectFrom($this->coach)->assertCreated();
        $this->deposit = CashDeposit::query()->findOrFail(
            cashApi($this->coach, 'POST', 'me/cash-box/deposits', ['amount' => 200000, 'money_account_id' => $this->bank->id, 'deposited_on' => '2026-09-02', 'reference' => 'Boleta 5521'])->json('data.id'),
        );
    });

    it('ve cuánto tiene cada uno y los depósitos por confirmar', function () {
        cashApi($this->treasurer, 'GET', 'cash-boxes')
            ->assertOk()
            ->assertJsonPath('data.total', 300000)
            ->assertJsonPath('data.boxes.0.name', 'Caja de Juan Pérez')
            ->assertJsonPath('data.boxes.0.holder.name', 'Juan Pérez')
            ->assertJsonPath('data.boxes.0.holder.active', true)
            ->assertJsonPath('data.boxes.0.balance', 300000)
            ->assertJsonPath('data.boxes.0.pending_deposits', 200000)
            ->assertJsonPath('data.boxes.0.last_movement_on', '2026-09-03')
            ->assertJsonPath('data.deposits.0.id', $this->deposit->id);

        cashApi($this->coach, 'GET', 'cash-boxes')->assertForbidden();
        cashApi($this->coach, 'POST', "cash-deposits/{$this->deposit->id}/confirm")->assertForbidden();
    });

    it('confirmar registra la transferencia y avisa', function () {
        cashApi($this->treasurer, 'POST', "cash-deposits/{$this->deposit->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmado');

        $box = MoneyAccount::cashBoxOf($this->coach, $this->jakare);
        $transfer = Transfer::query()->sole();
        expect($transfer->from_account_id)->toBe($box->id)
            ->and($transfer->to_account_id)->toBe($this->bank->id)
            ->and($transfer->transferred_on->toDateString())->toBe('2026-09-02')
            ->and($transfer->description)->toBe('Depósito de efectivo · Boleta 5521')
            ->and($box->balance())->toBe(100000)
            ->and($this->bank->balance())->toBe(200000)
            ->and($this->deposit->fresh()->transfer_id)->toBe($transfer->id);

        Notification::assertSentTo($this->coach, CashDepositReviewed::class, fn (CashDepositReviewed $n) => $n->body === 'Confirmamos tu depósito de ₲ 200.000 en Banco Itaú.');

        cashApi($this->treasurer, 'POST', "cash-deposits/{$this->deposit->id}/confirm")->assertUnprocessable();
        $movements = cashApi($this->coach, 'GET', 'me/cash-box')
            ->assertJsonPath('data.available', 100000)
            ->json('data.movements');
        // El depósito va con su fecha (antes del cobro de hoy).
        expect(collect($movements)->pluck('kind')->all())->toBe(['cobro', 'deposito'])
            ->and($movements[1]['amount'])->toBe(-200000);
    });

    it('rechazar deja la plata en su caja y avisa el motivo', function () {
        cashApi($this->treasurer, 'POST', "cash-deposits/{$this->deposit->id}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
        cashApi($this->treasurer, 'POST', "cash-deposits/{$this->deposit->id}/reject", ['reason' => 'No llegó al banco.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rechazado')
            ->assertJsonPath('data.rejection_reason', 'No llegó al banco.');

        expect(MoneyAccount::cashBoxOf($this->coach, $this->jakare)->balance())->toBe(300000)
            ->and(Transfer::query()->count())->toBe(0);
        Notification::assertSentTo($this->coach, CashDepositReviewed::class, fn (CashDepositReviewed $n) => $n->body === 'No confirmamos tu depósito de ₲ 200.000: No llegó al banco.');
    });

    it('un depósito confirmado con la transferencia anulada se ve anulado', function () {
        cashApi($this->treasurer, 'POST', "cash-deposits/{$this->deposit->id}/confirm")->assertOk();
        app(TransferFunds::class)->void(Transfer::query()->sole(), 'No era', $this->treasurer);

        cashApi($this->coach, 'GET', 'me/cash-box')
            ->assertJsonPath('data.balance', 300000)
            ->assertJsonPath('data.deposits.0.status', 'anulado');
    });

    it('no ve los de otra organización', function () {
        $other = Organization::factory()->create(['slug' => 'otro']);
        $treasurer = memberOf($other);
        app(RoleAssigner::class)->assign($other, $treasurer, OrganizationRole::Treasurer, endsOn: now()->addYear());

        $this->actingAs($treasurer, 'sanctum')->postJson("/api/v1/cash-deposits/{$this->deposit->id}/confirm", [], ['X-Organization' => 'otro'])
            ->assertNotFound();
        $this->actingAs($treasurer, 'sanctum')->getJson('/api/v1/cash-boxes', ['X-Organization' => 'otro'])
            ->assertOk()->assertJsonPath('data.boxes', []);
        app(CurrentOrganization::class)->set($this->jakare);
        expect($this->deposit->fresh()->status)->toBe(CashDepositStatus::Pending);
    });
});

describe('panel', function () {
    beforeEach(function () {
        collectFrom($this->coach)->assertCreated();
        cashApi($this->coach, 'POST', 'me/cash-box/deposits', ['amount' => 100000, 'money_account_id' => $this->bank->id, 'deposited_on' => '2026-09-03'])->assertCreated();
        cashApi($this->coach, 'POST', 'me/cash-box/deposits', ['amount' => 50000, 'money_account_id' => $this->cash->id, 'deposited_on' => '2026-09-03'])->assertCreated();
        app(CurrentOrganization::class)->set($this->jakare);
        // Las peticiones a la API dejaron sanctum como guard por defecto.
        auth()->shouldUse('web');
        $this->actingAs($this->treasurer, 'web');
        filament()->setTenant($this->jakare);
    });

    it('lista los depósitos, confirma y rechaza', function () {
        [$first, $second] = CashDeposit::query()->orderBy('id')->get()->all();

        $this->get('/admin/jakare/depositos-de-efectivo')->assertOk()->assertSee('Juan Pérez')
            // El menú con mayúscula solo al principio (N5).
            ->assertSee('Depósitos de efectivo')->assertDontSee('Depósitos De Efectivo');

        Livewire::test(ManageCashDeposits::class)
            ->assertCanSeeTableRecords([$first, $second])
            // Confirmar pregunta antes, con quién, cuánto, a qué cuenta y cuándo (N4).
            ->mountTableAction('confirm', $first)
            ->assertMountedActionModalSee(['¿Confirmás que llegaron ₲ 100.000 de Juan Pérez a '.$this->bank->name.'?', 'Depositado el 03/09/2026'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        expect(CashDepositResource::confirmQuestion($second))->toBe('¿Confirmás que llegaron ₲ 50.000 de Juan Pérez a '.$this->cash->name.'?');

        Livewire::test(ManageCashDeposits::class)
            ->callTableAction('reject', $second, data: ['reason' => 'No me lo dio.'])
            ->assertHasNoTableActionErrors();

        expect($first->fresh()->status)->toBe(CashDepositStatus::Confirmed)
            ->and($second->fresh()->status)->toBe(CashDepositStatus::Rejected)
            ->and(MoneyAccount::cashBoxOf($this->coach, $this->jakare)->balance())->toBe(200000);
    });

    it('las cuentas muestran quién tiene cada caja', function () {
        Livewire::test(ListMoneyAccounts::class)
            ->assertCanSeeTableRecords([MoneyAccount::cashBoxOf($this->coach, $this->jakare)])
            ->assertSee('Juan Pérez');
    });

    it('el técnico no entra a los depósitos del panel', function () {
        $this->actingAs($this->coach, 'web');

        $this->get('/admin/jakare/depositos-de-efectivo')->assertForbidden();
    });
});

function registerTransfer(User $user, array $data = [])
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return test()->actingAs($user, 'sanctum')->post('/api/v1/collections/transfers', [
        'student_id' => test()->mateo->id,
        'amount' => 300000,
        'paid_on' => '2026-09-02',
        'proof' => UploadedFile::fake()->image('captura-whatsapp.jpg'),
        'charge_ids' => [cashCharge(test()->mateo, '2026-08')->id, cashCharge(test()->sofia, '2026-08')->id],
        'money_account_id' => test()->bank->id,
        'guardian_id' => test()->guardian->id,
        'reference' => 'Transf. 99812',
        ...$data,
    ], ['X-Organization' => 'jakare', 'Accept' => 'application/json']);
}

describe('transferencia que la familia le mandó al club', function () {
    beforeEach(function () {
        Storage::fake('local');
    });

    it('la ficha de cobro trae las cuentas y si se aprueba al registrarla', function () {
        $box = MoneyAccount::ensureCashBoxOf($this->coach, $this->jakare);

        cashApi($this->coach, 'GET', "collections/students/{$this->mateo->id}")
            ->assertJsonPath('data.transfer_accounts', [['id' => $this->bank->id, 'name' => 'Banco Itaú']])
            ->assertJsonPath('data.approves_transfers', false)
            ->assertJsonPath('data.cash_box.id', $box->id);
        cashApi($this->treasurer, 'GET', "collections/students/{$this->mateo->id}")
            ->assertJsonPath('data.approves_transfers', true);
    });

    it('el técnico la registra: queda en revisión con la imagen y quién la registró', function () {
        $response = registerTransfer($this->coach)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pendiente')
            ->assertJsonPath('data.registered_by', 'Juan Pérez')
            ->assertJsonPath('data.proof_name', 'captura-whatsapp.jpg')
            ->assertJsonPath('data.receipt_number', null)
            ->assertJsonPath('message', 'Transferencia registrada. Queda en revisión hasta que Laura Gómez la apruebe.');

        $report = PaymentReport::query()->sole();
        expect($report->registered_by_staff)->toBeTrue()
            ->and($report->user_id)->toBe($this->coach->id)
            ->and($report->guardian_id)->toBe($this->guardian->id)
            ->and($report->family_id)->toBe($this->family->id)
            ->and(Payment::query()->count())->toBe(0);
        Storage::disk('local')->assertExists($report->proof_path);
        expect($response->json('data.proof_url'))->toContain('/comprobantes-de-pago/'.$report->id);

        Notification::assertSentTo($this->treasurer, PaymentReported::class, fn (PaymentReported $n) => $n->body === 'Juan Pérez registró una transferencia de ₲ 300.000 (Familia Benítez).');

        // La familia la ve en su estado de cuenta.
        cashApi($this->tutor, 'GET', 'account')
            ->assertJsonPath('data.payment_reports.0.registered_by', 'Juan Pérez')
            ->assertJsonPath('data.pending_reports_amount', 300000);

        // El tesorero la aprueba: pago por transferencia al banco, recibo a la familia y aviso al técnico.
        cashApi($this->treasurer, 'POST', "payment-reports/{$report->id}/approve")->assertOk()->assertJsonPath('data.status', 'aprobado');
        $payment = Payment::query()->sole();
        expect($payment->method)->toBe(PaymentMethod::Transfer)
            ->and($payment->money_account_id)->toBe($this->bank->id)
            ->and($payment->guardian_id)->toBe($this->guardian->id)
            ->and($payment->notes)->toBe('Transferencia registrada por Juan Pérez desde la app.');
        Notification::assertSentTo($this->tutor, PaymentReportReviewed::class, fn (PaymentReportReviewed $n) => $n->body === 'Aprobamos tu pago de ₲ 300.000. Recibo N° 000001.');
        Notification::assertSentTo($this->coach, PaymentReportReviewed::class, fn (PaymentReportReviewed $n) => $n->body === 'Aprobamos la transferencia de ₲ 300.000 de la Familia Benítez que registraste. Recibo N° 000001.');
    });

    it('si el tesorero la rechaza, el motivo le llega a quien la registró', function () {
        registerTransfer($this->coach)->assertCreated();
        $report = PaymentReport::query()->sole();

        cashApi($this->treasurer, 'POST', "payment-reports/{$report->id}/reject", ['reason' => 'No está en el banco.'])->assertOk();

        Notification::assertSentTo($this->coach, PaymentReportReviewed::class, fn (PaymentReportReviewed $n) => $n->body === 'No aprobamos la transferencia de ₲ 300.000 de la Familia Benítez que registraste: No está en el banco.');
        Notification::assertNotSentTo($this->tutor, PaymentReportReviewed::class);
    });

    it('el tesorero la registra aprobada al instante, con recibo', function () {
        registerTransfer($this->treasurer, ['money_account_id' => null])
            ->assertCreated()
            ->assertJsonPath('data.status', 'aprobado')
            ->assertJsonPath('data.registered_by', 'Laura Gómez')
            ->assertJsonPath('data.receipt_number', '000001')
            ->assertJsonPath('message', 'Transferencia registrada. Recibo N° 000001.');

        $report = PaymentReport::query()->sole();
        expect($report->status)->toBe(PaymentReportStatus::Approved)
            ->and($report->reviewed_by)->toBe($this->treasurer->id)
            // Sin cuenta elegida, la primera bancaria del club.
            ->and($report->payment->money_account_id)->toBe($this->bank->id)
            ->and(cashCharge($this->mateo, '2026-08')->fresh()->pendingAmount())->toBe(0);
        Notification::assertNotSentTo($this->treasurer, PaymentReported::class);
        Notification::assertSentTo($this->tutor, PaymentReportReviewed::class);
        Notification::assertNotSentTo($this->treasurer, PaymentReportReviewed::class);
    });

    it('valida comprobante, cuotas, cuenta y permiso', function () {
        registerTransfer($this->coach, ['proof' => null])->assertUnprocessable()->assertJsonValidationErrors('proof');
        registerTransfer($this->coach, ['paid_on' => '2026-09-04'])->assertUnprocessable()->assertJsonValidationErrors('paid_on');
        registerTransfer($this->coach, ['money_account_id' => MoneyAccount::ensureCashBoxOf($this->coach, $this->jakare)->id])
            ->assertUnprocessable()->assertJsonValidationErrors('money_account_id');
        registerTransfer($this->coach, ['money_account_id' => $this->cash->id])->assertUnprocessable()->assertJsonValidationErrors('money_account_id');
        registerTransfer($this->coach, ['student_id' => $this->lucia->id])->assertNotFound();
        registerTransfer($this->tutor)->assertForbidden();

        registerTransfer($this->coach)->assertCreated();
        registerTransfer($this->coach, ['charge_ids' => [cashCharge($this->mateo, '2026-08')->id]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.charge_ids.0', 'Ya hay una transferencia en revisión para «Cuota agosto 2026».');
    });

    it('las cajas personales no aparecen al aprobar comprobantes', function () {
        collectFrom($this->coach)->assertCreated();
        registerTransfer($this->coach, ['charge_ids' => [cashCharge($this->mateo, '2026-09')->id], 'amount' => 150000])->assertCreated();

        cashApi($this->treasurer, 'GET', 'payment-reports')
            ->assertJsonPath('data.0.money_accounts', [['id' => $this->cash->id, 'name' => 'Caja'], ['id' => $this->bank->id, 'name' => 'Banco Itaú']]);

        $box = MoneyAccount::cashBoxOf($this->coach, $this->jakare);
        cashApi($this->treasurer, 'POST', 'payment-reports/'.PaymentReport::query()->sole()->id.'/approve', ['money_account_id' => $box->id])
            ->assertUnprocessable()->assertJsonValidationErrors('money_account_id');
    });
});

describe('registrar pago en el panel', function () {
    beforeEach(function () {
        MoneyAccount::ensureCashBoxOf($this->coach, $this->jakare);
        auth()->shouldUse('web');
        $this->actingAs($this->treasurer, 'web');
        filament()->setTenant($this->jakare);
    });

    it('en efectivo va a la caja de quien registra; con transferencia, al banco', function () {
        $page = Livewire::test(ManagePayments::class)->mountAction('register');

        // Sin caja todavía: "mi caja" (se crea al registrar). La caja de otro (el técnico) no se puede elegir.
        $page->assertSet('mountedActions.0.data.money_account_id', 'mi-caja')
            ->set('mountedActions.0.data.family_id', $this->family->id)
            ->set('mountedActions.0.data.amount', 150000)
            ->set('mountedActions.0.data.money_account_id', MoneyAccount::cashBoxOf($this->coach, $this->jakare)->id)
            ->callMountedAction()
            ->assertHasActionErrors(['money_account_id']);
        expect(Payment::query()->count())->toBe(0);

        $page->set('mountedActions.0.data.method', 'transferencia')
            ->assertSet('mountedActions.0.data.money_account_id', $this->bank->id)
            ->set('mountedActions.0.data.method', 'efectivo')
            ->assertSet('mountedActions.0.data.money_account_id', 'mi-caja')
            ->set('mountedActions.0.data.family_id', $this->family->id)
            ->set('mountedActions.0.data.amount', 150000)
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $box = MoneyAccount::cashBoxOf($this->treasurer, $this->jakare);
        expect($box->name)->toBe('Caja de Laura Gómez')
            ->and(Payment::query()->sole()->money_account_id)->toBe($box->id)
            ->and($box->balance())->toBe(150000);

        // Con la caja ya creada, es la cuenta por defecto.
        Livewire::test(ManagePayments::class)->mountAction('register')
            ->assertSet('mountedActions.0.data.money_account_id', $box->id);
    });

    it('la Caja del club sigue elegible', function () {
        Livewire::test(ManagePayments::class)
            ->mountAction('register')
            ->set('mountedActions.0.data.family_id', $this->family->id)
            ->set('mountedActions.0.data.amount', 150000)
            ->set('mountedActions.0.data.money_account_id', $this->cash->id)
            ->callMountedAction()
            ->assertHasNoActionErrors();

        expect(Payment::query()->sole()->money_account_id)->toBe($this->cash->id);
    });
});

describe('comprobantes rechazados en el estado de cuenta', function () {
    function rejectedReport(array $chargeIds, string $reviewedAt = '2026-09-02 10:00:00'): PaymentReport
    {
        return PaymentReport::query()->create([
            'organization_id' => test()->jakare->id, 'family_id' => test()->family->id, 'user_id' => test()->tutor->id,
            'amount' => 150000, 'paid_on' => '2026-09-01', 'charge_ids' => $chargeIds, 'proof_path' => 'x.jpg', 'proof_name' => 'x.jpg',
            'status' => PaymentReportStatus::Rejected, 'reviewed_at' => $reviewedAt, 'rejection_reason' => 'No se lee.',
        ]);
    }

    it('se ve mientras alguna de sus cuotas siga pendiente; después queda como historial', function () {
        $report = rejectedReport([cashCharge($this->mateo, '2026-08')->id]);

        cashApi($this->tutor, 'GET', 'account')
            ->assertJsonPath('data.payment_reports.0.id', $report->id)
            ->assertJsonPath('data.payment_reports.0.open', true);

        collectFrom($this->coach, ['charge_ids' => [cashCharge($this->mateo, '2026-08')->id], 'amount' => 150000])->assertCreated();

        cashApi($this->tutor, 'GET', 'account')
            ->assertJsonPath('data.payment_reports.0.id', $report->id)
            ->assertJsonPath('data.payment_reports.0.open', false);
    });

    it('un rechazado sin cuotas se ve 30 días', function () {
        $recent = rejectedReport([], '2026-08-20 10:00:00');
        $old = rejectedReport([], '2026-07-20 10:00:00');

        expect($recent->fresh()->isOpen())->toBeTrue()
            ->and($old->fresh()->isOpen())->toBeFalse();
    });
});

describe('cobra directo a la Caja', function () {
    beforeEach(function () {
        // Óscar creó la academia (alta autoservicio): es el dueño y cobra directo a la Caja.
        $this->owner = memberOf($this->jakare, ['name' => 'Óscar Benítez']);
        app(RoleAssigner::class)->assign($this->jakare, $this->owner, OrganizationRole::Admin);
        $this->jakare->forceFill(['owner_id' => $this->owner->id])->save();
        $this->jakare->memberships()->where('user_id', $this->owner->id)->update(['collects_to_org_cash' => true]);
    });

    it('el dueño cobra a la Caja del club, sin caja propia ni depósito; queda quién cobró', function () {
        cashApi($this->owner, 'GET', 'organization')->assertJsonPath('data.membership.collects_to_org_cash', true);
        cashApi($this->owner, 'GET', "collections/students/{$this->mateo->id}")
            ->assertOk()
            ->assertJsonPath('data.collects_to_org_cash', true)
            ->assertJsonPath('data.default_collect_account_id', $this->cash->id)
            ->assertJsonPath('data.collect_accounts.*.name', ['Caja', 'Banco Itaú']);

        collectFrom($this->owner)
            ->assertCreated()
            ->assertJsonPath('data.cash_box', null)
            ->assertJsonPath('data.account.name', 'Caja');

        $payment = Payment::query()->latest('id')->sole();
        expect($payment->money_account_id)->toBe($this->cash->id)
            ->and($payment->created_by)->toBe($this->owner->id)
            ->and(MoneyAccount::cashBoxOf($this->owner, $this->jakare))->toBeNull()
            ->and($this->cash->balance())->toBe(300000);

        // Puede elegir otra cuenta del club, no una caja personal.
        $coachBox = MoneyAccount::ensureCashBoxOf($this->coach, $this->jakare);
        collectFrom($this->owner, ['charge_ids' => [], 'amount' => 1000, 'money_account_id' => $coachBox->id])
            ->assertUnprocessable()->assertJsonValidationErrors('money_account_id');
        collectFrom($this->owner, ['charge_ids' => [], 'amount' => 1000, 'money_account_id' => $this->bank->id])
            ->assertCreated()->assertJsonPath('data.account.name', 'Banco Itaú');
    });

    it('los demás (también un segundo admin) rinden a su caja como siempre', function () {
        $admin = memberOf($this->jakare, ['name' => 'Segundo Admin']);
        app(RoleAssigner::class)->assign($this->jakare, $admin, OrganizationRole::Admin);

        cashApi($this->coach, 'GET', "collections/students/{$this->mateo->id}")
            ->assertJsonPath('data.collects_to_org_cash', false)
            ->assertJsonPath('data.collect_accounts', []);
        collectFrom($this->coach, ['money_account_id' => $this->cash->id])
            ->assertUnprocessable()->assertJsonPath('errors.money_account_id.0', 'Lo que cobrás queda en tu caja hasta que lo deposites.');

        collectFrom($admin)->assertCreated()->assertJsonPath('data.cash_box.name', 'Caja de Segundo Admin');
        cashApi($admin, 'GET', 'me/cash-box')->assertJsonPath('data.collects_to_org_cash', false);
    });

    it('quien administra los miembros lo cambia; queda quién y la plata de la caja no se mueve', function () {
        collectFrom($this->coach)->assertCreated();
        $box = MoneyAccount::cashBoxOf($this->coach, $this->jakare);

        // El tesorero valida comprobantes pero no administra miembros: no ve ni cambia quién cobra directo.
        expect(cashApi($this->treasurer, 'GET', 'cash-boxes')->json('data'))->not->toHaveKey('collectors');
        cashApi($this->treasurer, 'PUT', "cash-collectors/{$this->coach->id}", ['collects_to_org_cash' => true])->assertForbidden();

        $collectors = cashApi($this->owner, 'GET', 'cash-boxes')->assertOk()->json('data.collectors');
        expect(collect($collectors)->firstWhere('user_id', $this->owner->id))->toMatchArray(['collects_to_org_cash' => true, 'owner' => true])
            ->and(collect($collectors)->firstWhere('user_id', $this->coach->id))->toMatchArray(['collects_to_org_cash' => false, 'owner' => false])
            ->and(collect($collectors)->pluck('user_id'))->not->toContain($this->tutor->id);

        cashApi($this->owner, 'PUT', "cash-collectors/{$this->coach->id}", ['collects_to_org_cash' => true])
            ->assertOk()->assertJsonPath('data.collects_to_org_cash', true);
        cashApi($this->owner, 'PUT', "cash-collectors/{$this->tutor->id}", ['collects_to_org_cash' => true])->assertNotFound();

        $membership = $this->jakare->memberships()->where('user_id', $this->coach->id)->sole();
        expect($membership->collects_to_org_cash_changed_by)->toBe($this->owner->id)
            ->and($membership->collects_to_org_cash_changed_at)->not->toBeNull()
            ->and(Activity::query()->where('description', 'Cobra directo a la Caja')->sole()->causer_id)->toBe($this->owner->id);

        // Lo que tenía en su caja sigue ahí (lo deposita); lo nuevo va a la Caja.
        expect($box->balance())->toBe(300000);
        collectFrom($this->coach, ['charge_ids' => [], 'amount' => 5000])->assertCreated()->assertJsonPath('data.account.name', 'Caja');
        expect($box->balance())->toBe(300000);
        cashApi($this->coach, 'GET', 'me/cash-box')->assertJsonPath('data.available', 300000)->assertJsonPath('data.collects_to_org_cash', true);
    });

    it('al crear el club, el dueño cobra directo; quien acepta una invitación de técnico, no', function () {
        $user = User::factory()->create(['name' => 'Nueva Dueña']);
        $organization = app(RegisterOrganization::class)->handle($user, ['name' => 'Academia Nueva', 'type' => 'academy', 'slug' => 'academia-nueva']);

        expect($organization->owner_id)->toBe($user->id)
            ->and(CashCollectionAccess::collectsToOrgCash($user, $organization))->toBeTrue()
            ->and(CashCollectionAccess::collectsToOrgCash($this->coach, $this->jakare))->toBeFalse();
    });

    it('en el panel: Miembros lo muestra y lo cambia; Registrar pago con efectivo propone la Caja', function () {
        auth()->shouldUse('web');
        $this->actingAs($this->owner, 'web');
        filament()->setTenant($this->jakare);
        app(CurrentOrganization::class)->set($this->jakare);

        $coachMembership = $this->jakare->memberships()->where('user_id', $this->coach->id)->sole();
        Livewire::test(ListMembers::class)
            ->assertSee('Cobra directo a la Caja')
            ->assertSee('Rinde lo que cobra')
            ->callTableAction('collectsToOrgCash', $coachMembership)
            ->assertHasNoTableActionErrors();
        expect($coachMembership->fresh()->collects_to_org_cash)->toBeTrue();

        Livewire::test(ManagePayments::class)
            ->mountAction('register')
            ->assertActionDataSet(['money_account_id' => $this->cash->id])
            ->setActionData([
                'family_id' => $this->family->id, 'amount' => 150000, 'method' => PaymentMethod::Cash->value,
                'charge_ids' => [cashCharge($this->mateo, '2026-08')->id],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $payment = Payment::query()->latest('id')->sole();
        expect($payment->money_account_id)->toBe($this->cash->id)->and($payment->created_by)->toBe($this->owner->id);

        Livewire::test(ManagePayments::class)->assertSee('Óscar Benítez');
        $this->get(ReceiptController::signedUrl($payment))->assertOk();
        $html = view('receipts.show', [
            'payment' => $payment->load(['organization', 'family', 'guardian', 'moneyAccount', 'creator', 'allocations.charge.student']),
            'organization' => $payment->organization,
            'allocations' => $payment->originalAllocations(),
            'credit' => $payment->creditGenerated(),
            'money' => fn (int $amount) => Money::pyg($amount),
        ])->render();
        expect($html)->toContain('Cobró: Óscar Benítez');
    });
});

describe('a quién le llega el recibo (N8)', function () {
    beforeEach(function () {
        // Carlos Ortiz, el otro tutor de la familia: solo tiene celular, sin cuenta.
        $this->carlos = Guardian::factory()->for($this->jakare)->create([
            'first_name' => 'Carlos', 'last_name' => 'Ortiz', 'user_id' => null, 'email' => null,
            'phone' => '0981 123456', 'family_id' => $this->family->id,
        ]);
        $this->guardian->update(['first_name' => 'Ana', 'last_name' => 'Benítez']);
    });

    it('el cobro dice la verdad por tutor y trae el WhatsApp con el link al recibo de 30 días', function () {
        $response = collectFrom($this->coach)->assertCreated();
        $reach = collect($response->json('data.notice.reach'))->keyBy('name');

        expect($reach['Ana Benítez']['channels'])->toContain('app')
            ->and($reach['Ana Benítez']['description'])->toStartWith('A Ana Benítez le llega en la app')
            ->and($reach['Ana Benítez']['whatsapp_url'])->toBeNull()
            ->and($reach['Carlos Ortiz']['channels'])->toBe([])
            ->and($reach['Carlos Ortiz']['description'])->toStartWith('Carlos Ortiz no tiene la app: no le llega.')
            ->and($reach['Carlos Ortiz']['whatsapp_phone'])->toBe('595981123456');

        $url = $response->json('data.notice.receipt_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        expect((int) $query['expires'])->toBe(now()->addDays(30)->getTimestamp())
            ->and($reach['Carlos Ortiz']['whatsapp_url'])->toStartWith('https://wa.me/595981123456?text=')
            ->and(rawurldecode($reach['Carlos Ortiz']['whatsapp_url']))->toContain($url)
            ->and($response->json('data.notice.message'))->toContain('recibo N°', 'el link vale 30 días');

        // El link largo sigue abriendo el recibo a los 29 días; el de siempre dura media hora.
        $short = ReceiptController::signedUrl(Payment::query()->sole());
        $this->travel(29)->days();
        $this->get($url)->assertOk();
        $this->get($short)->assertForbidden();
    });

    it('la transferencia aprobada al registrarla también dice a quién le llega', function () {
        Storage::fake('local');

        cashApi($this->treasurer, 'POST', 'collections/transfers', [
            'student_id' => $this->mateo->id,
            'amount' => 150000,
            'paid_on' => '2026-09-02',
            'proof' => UploadedFile::fake()->image('captura.jpg'),
        ])->assertCreated()
            ->assertJsonPath('notice.reach.1.name', 'Carlos Ortiz')
            ->assertJsonPath('notice.reach.1.channels', []);
    });

    it('en el panel, registrar un pago avisa a la familia y dice la verdad con WhatsApp para quien no tiene la app', function () {
        auth()->shouldUse('web');
        $this->actingAs($this->treasurer, 'web');
        filament()->setTenant($this->jakare);

        Livewire::test(ManagePayments::class)->mountAction('register')
            ->set('mountedActions.0.data.family_id', $this->family->id)
            ->set('mountedActions.0.data.amount', 150000)
            ->set('mountedActions.0.data.method', 'transferencia')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        Notification::assertSentTo($this->tutor, PaymentReceived::class, fn (PaymentReceived $n) => str_contains($n->body, 'por transferencia'));

        $sent = new Notifications;
        $sent->mount();
        $notification = $sent->notifications->last();
        expect($notification->getBody())->toContain('A Ana Benítez le llega en la app', 'Carlos Ortiz no tiene la app: no le llega.')
            ->and(collect($notification->getActions())->map(fn ($action) => $action->getLabel()))->toContain('Mandar recibo por WhatsApp a Carlos Ortiz');
    });
});

describe('quién confirma (N10) y Mi caja (N11)', function () {
    it('Mi caja trae quiénes confirman los depósitos, sin quien deposita', function () {
        $admin = memberOf($this->jakare, ['name' => 'Óscar Giménez']);
        app(RoleAssigner::class)->assign($this->jakare, $admin, OrganizationRole::Admin);

        $names = fn (User $user) => collect(cashApi($user, 'GET', 'me/cash-box')->assertOk()->json('data.confirmers'))->pluck('name')->sort()->values()->all();

        expect($names($this->coach))->toBe(['Laura Gómez', 'Óscar Giménez'])
            ->and($names($this->treasurer))->toBe(['Óscar Giménez']);
    });

    it('la transferencia en revisión nombra a quién la aprueba', function () {
        Storage::fake('local');

        cashApi($this->coach, 'POST', 'collections/transfers', [
            'student_id' => $this->mateo->id,
            'amount' => 150000,
            'paid_on' => '2026-09-02',
            'proof' => UploadedFile::fake()->image('captura.jpg'),
        ])->assertCreated()
            ->assertJsonPath('message', 'Transferencia registrada. Queda en revisión hasta que Laura Gómez la apruebe.');
    });

    it('la caja cerrada nombra a quién puede reabrirla, sin quien la tiene', function () {
        $oscar = memberOf($this->jakare, ['name' => 'Óscar Giménez']);
        app(RoleAssigner::class)->assign($this->jakare, $oscar, OrganizationRole::Admin);
        $reopeners = fn (User $user) => collect(cashApi($user, 'GET', 'me/cash-box')->assertOk()->json('data.reopeners'))->pluck('name')->sort()->values()->all();

        // Reabren quienes editan cuentas: el admin y, por defecto, el tesorero.
        expect($reopeners($this->coach))->toBe(['Laura Gómez', 'Óscar Giménez'])
            ->and($reopeners($oscar))->toBe(['Laura Gómez']);

        MoneyAccount::ensureCashBoxOf($this->coach, $this->jakare)->update(['is_active' => false]);
        collectFrom($this->coach)->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'Tu caja está cerrada. Hablá con Laura Gómez o Óscar Giménez para reabrirla.');
    });

    it('el texto de la caja cerrada: uno, dos o el genérico', function () {
        expect(CashCollectionAccess::closedBoxMessage([['name' => 'Óscar Giménez']], $this->jakare))
            ->toBe('Tu caja está cerrada. Hablá con Óscar Giménez para reabrirla.')
            ->and(CashCollectionAccess::closedBoxMessage([['name' => 'Óscar Giménez'], ['name' => 'Ana Duarte']], $this->jakare))
            ->toBe('Tu caja está cerrada. Hablá con Óscar Giménez o Ana Duarte para reabrirla.')
            ->and(CashCollectionAccess::closedBoxMessage([['name' => 'A'], ['name' => 'B'], ['name' => 'C']], $this->jakare))
            ->toBe('Tu caja está cerrada. Hablá con quien maneja las cuentas del club para reabrirla.')
            ->and(CashCollectionAccess::closedBoxMessage([], $this->jakare))
            ->toBe('Tu caja está cerrada. Hablá con quien maneja las cuentas del club para reabrirla.');
    });

    it('GET organization manda el saldo de la caja personal', function () {
        $balance = fn (User $user) => cashApi($user, 'GET', 'organization')->json('data.membership.cash_box_balance');

        expect($balance($this->coach))->toBe(0);
        collectFrom($this->coach)->assertCreated();
        expect($balance($this->coach))->toBe(300000);
    });
});
