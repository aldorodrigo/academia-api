<?php

use App\Enums\OrganizationRole;
use App\Enums\PaymentMethod;
use App\Enums\PaymentReportStatus;
use App\Filament\Resources\MoneyAccounts\Pages\ListMoneyAccounts;
use App\Filament\Resources\PaymentReports\Pages\ManagePaymentReports;
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
use App\Models\User;
use App\Notifications\PaymentReported;
use App\Notifications\PaymentReportReviewed;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->travelTo('2026-09-03 12:00:00');
    Storage::fake('local');
    Notification::fake();

    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    app(CurrentOrganization::class)->set($this->jakare);

    $season = Season::factory()->for($this->jakare)->create(['name' => '2026', 'starts_on' => '2026-02-01', 'ends_on' => '2026-11-30']);
    $group = Group::factory()->for(Program::factory()->for($this->jakare)->create(['name' => 'Fútbol']))->create(['name' => 'Sub-10', 'organization_id' => $this->jakare->id]);
    Tariff::factory()->create(['fee_concept_id' => FeeConcept::monthlyFee($this->jakare)->id, 'season_id' => $season->id, 'amount' => 150000, 'valid_from' => '2026-02-01']);

    $this->tutor = memberOf($this->jakare, ['name' => 'Ana Benítez']);
    $this->family = Family::factory()->for($this->jakare)->create(['name' => 'Familia Benítez']);
    $this->guardian = Guardian::factory()->for($this->jakare)->create(['user_id' => $this->tutor->id, 'family_id' => $this->family->id]);
    $this->mateo = Student::factory()->for($this->jakare)->create(['first_name' => 'Mateo', 'birth_date' => '2016-03-14', 'family_id' => $this->family->id]);
    $this->sofia = Student::factory()->for($this->jakare)->create(['first_name' => 'Sofía', 'birth_date' => '2018-07-02', 'family_id' => $this->family->id]);
    $this->guardian->students()->attach([$this->mateo->id, $this->sofia->id]);
    foreach ([$this->mateo, $this->sofia] as $student) {
        Enrollment::factory()->create(['student_id' => $student->id, 'group_id' => $group->id, 'season_id' => $season->id]);
    }
    issueMonth($this->jakare, '2026-08');
    issueMonth($this->jakare, '2026-09');

    $this->cash = MoneyAccount::query()->where('name', 'Caja')->sole(); // La crea la organización.
    $this->bank = MoneyAccount::factory()->for($this->jakare)->create([
        'name' => 'Banco Itaú', 'type' => 'banco', 'transfer_details' => "Cuenta corriente 123456\nTitular: Club Jakare",
    ]);

    $this->treasurer = memberOf($this->jakare, ['name' => 'Laura Gómez']);
    app(RoleAssigner::class)->assign($this->jakare, $this->treasurer, OrganizationRole::Treasurer, endsOn: now()->addYear());
});

function chargeFor(Student $student, string $period): Charge
{
    return Charge::query()->where('student_id', $student->id)->whereDate('period', "{$period}-01")->sole();
}

function reportPayment(User $user, array $data = [])
{
    return test()->actingAs($user, 'sanctum')->post('/api/v1/payment-reports', [
        'amount' => 300000,
        'paid_on' => '2026-09-02',
        'proof' => UploadedFile::fake()->image('comprobante.jpg'),
        'charge_ids' => [chargeFor(test()->mateo, '2026-08')->id, chargeFor(test()->sofia, '2026-08')->id],
        'money_account_id' => test()->bank->id,
        'reference' => 'Transf. 99812',
        ...$data,
    ], ['X-Organization' => 'jakare', 'Accept' => 'application/json']);
}

function asReviewer(User $user, string $method, string $uri, array $data = [])
{
    return test()->actingAs($user, 'sanctum')->json($method, "/api/v1/{$uri}", $data, ['X-Organization' => 'jakare']);
}

describe('tutor', function () {
    it('informa una transferencia con el comprobante y queda en revisión', function () {
        $response = reportPayment($this->tutor)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pendiente')
            ->assertJsonPath('data.status_label', 'En revisión')
            ->assertJsonPath('data.amount', 300000)
            ->assertJsonPath('data.paid_on', '2026-09-02')
            ->assertJsonPath('data.money_account.name', 'Banco Itaú')
            ->assertJsonPath('data.charges.0.description', 'Cuota agosto 2026')
            ->assertJsonPath('data.charges.0.pending_amount', 150000)
            ->assertJsonCount(2, 'data.charges')
            ->assertJsonPath('data.proof_name', 'comprobante.jpg')
            ->assertJsonPath('data.receipt_number', null);

        $report = PaymentReport::query()->sole();
        expect($report->family_id)->toBe($this->family->id)
            ->and($report->user_id)->toBe($this->tutor->id);
        Storage::disk('local')->assertExists($report->proof_path);
        expect($response->json('data.proof_url'))->toContain('/comprobantes-de-pago/'.$report->id);

        // No impacta en la cuenta hasta que lo validan.
        expect(Payment::query()->count())->toBe(0);
        Notification::assertSentTo($this->treasurer, PaymentReported::class, fn (PaymentReported $n) => $n->body === 'Ana Benítez informó una transferencia de ₲ 300.000 (Familia Benítez).');
        Notification::assertNotSentTo($this->tutor, PaymentReported::class);
    });

    it('la cuenta trae los datos para transferir y lo informado', function () {
        reportPayment($this->tutor)->assertCreated();

        $account = $this->actingAs($this->tutor, 'sanctum')->getJson('/api/v1/account', ['X-Organization' => 'jakare'])
            ->assertOk()
            ->assertJsonPath('data.transfer_accounts', [['id' => $this->bank->id, 'name' => 'Banco Itaú', 'details' => "Cuenta corriente 123456\nTitular: Club Jakare"]])
            ->assertJsonPath('data.pending_reports_amount', 300000)
            ->assertJsonCount(1, 'data.payment_reports')
            ->assertJsonPath('data.payment_reports.0.status', 'pendiente');
        // El saldo no cambia hasta que lo aprueban.
        expect($account->json('data.balance'))->toBe(600000);

        // En la ficha de cada hijo, solo si incluye cuotas suyas.
        $this->actingAs($this->tutor, 'sanctum')->getJson("/api/v1/students/{$this->sofia->id}/account", ['X-Organization' => 'jakare'])
            ->assertJsonCount(1, 'data.payment_reports');
        PaymentReport::query()->update(['charge_ids' => [chargeFor($this->mateo, '2026-08')->id]]);
        $this->actingAs($this->tutor, 'sanctum')->getJson("/api/v1/students/{$this->sofia->id}/account", ['X-Organization' => 'jakare'])
            ->assertJsonCount(0, 'data.payment_reports');
    });

    it('valida el comprobante, la fecha y el monto', function (array $data, string $field, string $message) {
        reportPayment($this->tutor, $data)->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);
    })->with([
        'sin comprobante' => [['proof' => null], 'proof', 'Adjuntá el comprobante de la transferencia.'],
        'no es foto ni PDF' => [['proof' => UploadedFile::fake()->create('planilla.xlsx', 10)], 'proof', 'El comprobante tiene que ser una foto o un PDF.'],
        'muy pesado' => [['proof' => UploadedFile::fake()->create('foto.jpg', 6000, 'image/jpeg')], 'proof', 'El comprobante pesa más de 5 MB.'],
        'fecha futura' => [['paid_on' => '2026-09-04'], 'paid_on', 'La fecha de la transferencia no puede ser futura.'],
        'monto cero' => [['amount' => 0], 'amount', 'El monto tiene que ser mayor a cero.'],
    ]);

    it('acepta un PDF y pago a cuenta sin cuotas ni cuenta', function () {
        reportPayment($this->tutor, ['proof' => UploadedFile::fake()->create('transferencia.pdf', 100, 'application/pdf'), 'charge_ids' => [], 'money_account_id' => null])
            ->assertCreated()
            ->assertJsonPath('data.charges', [])
            ->assertJsonPath('data.money_account', null);
    });

    it('no acepta cuotas ajenas, pagadas, en revisión ni cuentas sin datos', function () {
        $other = Student::factory()->for($this->jakare)->create(['family_id' => Family::factory()->for($this->jakare)->create()->id]);
        $foreign = Charge::factory()->create(['student_id' => $other->id]);

        reportPayment($this->tutor, ['charge_ids' => [$foreign->id]])
            ->assertJsonValidationErrors(['charge_ids' => 'Elegí cuotas de tus hijos.']);
        reportPayment($this->tutor, ['money_account_id' => $this->cash->id])
            ->assertJsonValidationErrors(['money_account_id' => 'Elegí una de las cuentas para transferir.']);

        reportPayment($this->tutor)->assertCreated();
        reportPayment($this->tutor, ['charge_ids' => [chargeFor($this->sofia, '2026-08')->id, chargeFor($this->sofia, '2026-09')->id]])
            ->assertJsonValidationErrors(['charge_ids' => 'Ya informaste un pago para «Cuota agosto 2026»; esperá a que lo revisen.']);

        // Ya pagada.
        asReviewer($this->treasurer, 'POST', 'payment-reports/'.PaymentReport::query()->sole()->id.'/approve')->assertOk();
        reportPayment($this->tutor, ['charge_ids' => [chargeFor($this->mateo, '2026-08')->id]])
            ->assertJsonValidationErrors(['charge_ids' => '«Cuota agosto 2026» ya está pagada.']);
    });

    it('retira su comprobante mientras está en revisión', function () {
        reportPayment($this->tutor)->assertCreated();
        $report = PaymentReport::query()->sole();

        asReviewer($this->treasurer, 'DELETE', "payment-reports/{$report->id}")->assertNotFound();
        asReviewer($this->tutor, 'DELETE', "payment-reports/{$report->id}")->assertNoContent();

        // Soft delete: no aparece, pero quedan el registro y el archivo.
        expect(PaymentReport::query()->count())->toBe(0);
        $this->assertSoftDeleted($report);
        Storage::disk('local')->assertExists($report->proof_path);

        reportPayment($this->tutor)->assertCreated();
        $report = PaymentReport::query()->sole();
        asReviewer($this->treasurer, 'POST', "payment-reports/{$report->id}/reject", ['reason' => 'No se lee.'])->assertOk();
        asReviewer($this->tutor, 'DELETE', "payment-reports/{$report->id}")
            ->assertJsonValidationErrors(['status' => 'Este comprobante ya fue revisado.']);
    });

    it('el archivo se abre con el link firmado', function () {
        $url = reportPayment($this->tutor)->json('data.proof_url');

        $this->get($url)->assertOk()->assertHeader('content-disposition', 'inline; filename=comprobante.jpg');
        $this->get('/comprobantes-de-pago/'.PaymentReport::query()->sole()->id)->assertForbidden();
    });
});

describe('quien valida', function () {
    it('el tesorero y el admin validan; el tutor no', function () {
        $admin = memberOf($this->jakare);
        app(RoleAssigner::class)->assign($this->jakare, $admin, OrganizationRole::Admin);

        $permissions = fn (User $user) => $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/organization', ['X-Organization' => 'jakare'])->json('data.membership.permissions');

        expect($permissions($this->treasurer))->toContain('review_payment_reports')
            ->and($permissions($admin))->toContain('review_payment_reports')
            ->and($permissions($this->tutor))->not->toContain('review_payment_reports');

        asReviewer($this->tutor, 'GET', 'payment-reports')->assertForbidden()
            ->assertJsonPath('message', 'No tenés permiso para validar comprobantes.');
        asReviewer($admin, 'GET', 'payment-reports')->assertOk();
    });

    it('con el permiso "Validar comprobantes" también valida', function () {
        $secretary = memberOf($this->jakare);
        app(RoleAssigner::class)->assign($this->jakare, $secretary, OrganizationRole::Secretary, endsOn: now()->addYear());
        asReviewer($secretary, 'GET', 'payment-reports')->assertForbidden();

        // La petición anterior dejó sanctum como guard por defecto y la caché de spatie cargada.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('Review:PaymentReports', 'web');
        Role::query()->where('organization_id', $this->jakare->id)->where('name', 'secretario')->sole()
            ->givePermissionTo('Review:PaymentReports');

        asReviewer($secretary->fresh(), 'GET', 'payment-reports')->assertOk();
    });

    it('lista los pendientes con la familia, lo que debe y las cuentas', function () {
        reportPayment($this->tutor)->assertCreated();

        asReviewer($this->treasurer, 'GET', 'payment-reports')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.family.name', 'Familia Benítez')
            ->assertJsonPath('data.0.family.students', ['Mateo', 'Sofía'])
            ->assertJsonPath('data.0.reported_by', 'Ana Benítez')
            ->assertJsonPath('data.0.pending_balance', 600000)
            ->assertJsonPath('data.0.money_accounts', [['id' => $this->cash->id, 'name' => 'Caja'], ['id' => $this->bank->id, 'name' => 'Banco Itaú']]);
    });

    it('aprobar registra el pago por transferencia con recibo y avisa al tutor', function () {
        reportPayment($this->tutor, ['amount' => 350000])->assertCreated();
        $report = PaymentReport::query()->sole();

        asReviewer($this->treasurer, 'POST', "payment-reports/{$report->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'aprobado')
            ->assertJsonPath('data.receipt_number', '000001')
            ->assertJsonPath('data.reported_by', 'Ana Benítez');

        $payment = Payment::query()->sole();
        expect($payment->method)->toBe(PaymentMethod::Transfer)
            ->and($payment->amount)->toBe(350000)
            ->and($payment->money_account_id)->toBe($this->bank->id)
            ->and($payment->guardian_id)->toBe($this->guardian->id)
            ->and($payment->reference)->toBe('Transf. 99812')
            ->and($payment->received_on->toDateString())->toBe('2026-09-02')
            // Las dos cuotas de agosto elegidas; lo que sobra queda a favor (no va a septiembre).
            ->and($payment->allocations->pluck('charge_id')->all())->toBe([chargeFor($this->mateo, '2026-08')->id, chargeFor($this->sofia, '2026-08')->id])
            ->and($payment->credit())->toBe(50000);
        expect($report->fresh())
            ->status->toBe(PaymentReportStatus::Approved)
            ->reviewed_by->toBe($this->treasurer->id)
            ->payment_id->toBe($payment->id);

        Notification::assertSentTo($this->tutor, PaymentReportReviewed::class, fn (PaymentReportReviewed $n) => $n->title === 'Pago aprobado'
            && $n->body === 'Aprobamos tu pago de ₲ 350.000. Recibo N° 000001.');

        // El tutor lo ve aprobado con su recibo, y ya no está en revisión.
        $this->actingAs($this->tutor, 'sanctum')->getJson('/api/v1/account', ['X-Organization' => 'jakare'])
            ->assertJsonPath('data.pending_reports_amount', 0)
            ->assertJsonPath('data.payment_reports.0.receipt_number', '000001')
            ->assertJsonPath('data.balance', 250000);

        asReviewer($this->treasurer, 'POST', "payment-reports/{$report->id}/approve")
            ->assertJsonValidationErrors(['status' => 'Este comprobante ya fue revisado.']);
        expect(Payment::query()->count())->toBe(1);
    });

    it('al aprobar se puede corregir la cuenta, la fecha y el monto', function () {
        reportPayment($this->tutor, ['charge_ids' => [], 'money_account_id' => null])->assertCreated();
        $report = PaymentReport::query()->sole();

        asReviewer($this->treasurer, 'POST', "payment-reports/{$report->id}/approve", [
            'money_account_id' => $this->cash->id, 'received_on' => '2026-09-01', 'amount' => 150000,
        ])->assertOk();

        $payment = Payment::query()->sole();
        expect($payment->money_account_id)->toBe($this->cash->id)
            ->and($payment->amount)->toBe(150000)
            ->and($payment->received_on->toDateString())->toBe('2026-09-01')
            // Pago a cuenta: del vencimiento más viejo al más nuevo.
            ->and($payment->allocations->pluck('charge_id')->all())->toBe([chargeFor($this->mateo, '2026-08')->id]);

        asReviewer($this->treasurer, 'POST', "payment-reports/{$report->id}/approve", ['received_on' => '2026-09-10'])
            ->assertJsonValidationErrors(['received_on' => 'La fecha no puede ser futura.']);
    });

    it('si no informó cuenta, entra en la primera bancaria', function () {
        reportPayment($this->tutor, ['money_account_id' => null])->assertCreated();

        asReviewer($this->treasurer, 'POST', 'payment-reports/'.PaymentReport::query()->sole()->id.'/approve')->assertOk();

        expect(Payment::query()->sole()->money_account_id)->toBe($this->bank->id);
    });

    it('rechazar pide el motivo y avisa al tutor', function () {
        reportPayment($this->tutor)->assertCreated();
        $report = PaymentReport::query()->sole();

        asReviewer($this->treasurer, 'POST', "payment-reports/{$report->id}/reject", ['reason' => ''])
            ->assertJsonValidationErrors(['reason' => 'Contale al tutor por qué no lo aprobás.']);
        asReviewer($this->treasurer, 'POST', "payment-reports/{$report->id}/reject", ['reason' => 'El comprobante no se lee.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rechazado')
            ->assertJsonPath('data.rejection_reason', 'El comprobante no se lee.');

        expect(Payment::query()->count())->toBe(0);
        Notification::assertSentTo($this->tutor, PaymentReportReviewed::class, fn (PaymentReportReviewed $n) => $n->title === 'Pago no aprobado'
            && $n->body === 'No pudimos aprobar tu pago de ₲ 300.000: El comprobante no se lee.');

        // Se puede volver a informar las mismas cuotas.
        reportPayment($this->tutor)->assertCreated();
        asReviewer($this->treasurer, 'GET', 'payment-reports')->assertJsonCount(1, 'data');
        asReviewer($this->treasurer, 'GET', 'payment-reports?status=todos')->assertJsonCount(2, 'data');
    });

    it('no ve los de otra organización', function () {
        reportPayment($this->tutor)->assertCreated();
        $report = PaymentReport::query()->sole();

        $other = Organization::factory()->create(['slug' => 'ajena']);
        $foreignTreasurer = memberOf($other);
        app(RoleAssigner::class)->assign($other, $foreignTreasurer, OrganizationRole::Treasurer, endsOn: now()->addYear());

        $this->actingAs($foreignTreasurer, 'sanctum')
            ->postJson("/api/v1/payment-reports/{$report->id}/approve", [], ['X-Organization' => 'ajena'])
            ->assertNotFound();
    });
});

describe('panel', function () {
    beforeEach(function () {
        $this->actingAs($this->treasurer);
        filament()->setTenant($this->jakare);
    });

    it('lista los comprobantes, aprueba y rechaza', function () {
        reportPayment($this->tutor)->assertCreated();
        reportPayment($this->tutor, ['charge_ids' => [chargeFor($this->mateo, '2026-09')->id], 'amount' => 150000])->assertCreated();
        [$first, $second] = PaymentReport::query()->orderBy('id')->get()->all();
        $this->actingAs($this->treasurer);

        $this->get('/admin/jakare/comprobantes')->assertOk()->assertSee('Familia Benítez');

        Livewire::test(ManagePaymentReports::class)
            ->assertCanSeeTableRecords([$first, $second])
            ->callTableAction('approve', $first, data: ['money_account_id' => $this->bank->id, 'received_on' => '2026-09-02', 'amount' => 300000])
            ->assertHasNoTableActionErrors()
            ->callTableAction('reject', $second, data: ['reason' => 'El monto no coincide.'])
            ->assertHasNoTableActionErrors();

        expect($first->fresh()->status)->toBe(PaymentReportStatus::Approved)
            ->and($second->fresh()->status)->toBe(PaymentReportStatus::Rejected)
            ->and(Payment::query()->sole()->amount)->toBe(300000);
    });

    it('un tutor no entra a comprobantes', function () {
        $this->actingAs($this->tutor);

        $this->get('/admin/jakare/comprobantes')->assertForbidden();
    });

    it('la cuenta bancaria guarda los datos para transferir', function () {
        $admin = memberOf($this->jakare);
        app(RoleAssigner::class)->assign($this->jakare, $admin, OrganizationRole::Admin);
        $this->actingAs($admin);

        Livewire::test(ListMoneyAccounts::class)
            ->callAction('create', data: ['name' => 'Ueno', 'type' => 'billetera', 'opening_balance' => 0, 'transfer_details' => 'Alias: JAKARE.UENO'])
            ->assertHasNoActionErrors();

        expect(MoneyAccount::query()->where('name', 'Ueno')->sole()->transfer_details)->toBe('Alias: JAKARE.UENO');
    });
});
