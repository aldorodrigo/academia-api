<?php

use App\Actions\Billing\RegisterPayment;
use App\Actions\Billing\VoidPayment;
use App\Enums\DiscountType;
use App\Enums\Gender;
use App\Enums\PaymentMethod;
use App\Http\Controllers\ReceiptController;
use App\Models\Charge;
use App\Models\DiscountRule;
use App\Models\Enrollment;
use App\Models\Family;
use App\Models\FeeConcept;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\LedgerEntry;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Program;
use App\Models\Season;
use App\Models\Student;
use App\Models\Tariff;
use App\Support\Money;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo('2026-09-03 12:00:00');
    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    app(CurrentOrganization::class)->set($this->jakare);

    $this->season = Season::factory()->for($this->jakare)->create(['name' => '2026', 'starts_on' => '2026-02-01', 'ends_on' => '2026-11-30']);
    $futbol = Program::factory()->for($this->jakare)->create(['name' => 'Fútbol']);
    $group = Group::factory()->for($futbol)->create(['name' => 'Sub-10', 'organization_id' => $this->jakare->id]);
    $this->monthly = FeeConcept::monthlyFee($this->jakare);
    Tariff::factory()->create(['fee_concept_id' => $this->monthly->id, 'season_id' => $this->season->id, 'amount' => 150000, 'valid_from' => '2026-02-01']);

    $this->family = Family::factory()->for($this->jakare)->create(['name' => 'Familia Benítez']);
    $this->guardian = Guardian::factory()->for($this->jakare)->create(['first_name' => 'Ana', 'last_name' => 'Benítez', 'family_id' => $this->family->id, 'user_id' => memberOf($this->jakare)->id]);
    $this->mateo = Student::factory()->for($this->jakare)->create(['first_name' => 'Mateo', 'birth_date' => '2016-03-14', 'family_id' => $this->family->id]);
    $this->sofia = Student::factory()->for($this->jakare)->create(['first_name' => 'Sofía', 'birth_date' => '2018-07-02', 'family_id' => $this->family->id]);
    $this->guardian->students()->attach([$this->mateo->id, $this->sofia->id]);
    foreach ([$this->mateo, $this->sofia] as $student) {
        Enrollment::factory()->create(['student_id' => $student->id, 'group_id' => $group->id, 'season_id' => $this->season->id]);
    }

    foreach (['2026-08-01', '2026-09-01'] as $period) {
        issueMonth($this->jakare, substr($period, 0, 7));
    }

    $this->bank = MoneyAccount::factory()->for($this->jakare)->create(['name' => 'Banco Itaú']);
});

function pay(int $amount, ?array $allocations = null, string $on = '2026-09-03'): Payment
{
    return app(RegisterPayment::class)->handle(
        test()->family, test()->bank, $amount, PaymentMethod::Transfer, CarbonImmutable::parse($on),
        payer: test()->guardian, allocations: $allocations,
    );
}

function chargeOf(Student $student, string $period): Charge
{
    return Charge::query()->where('student_id', $student->id)->whereDate('period', $period)->sole();
}

describe('libro mayor', function () {
    it('saldo = suma de movimientos; inmutable; se anula con contra-movimiento', function () {
        $entry = LedgerEntry::query()->create(['money_account_id' => $this->bank->id, 'occurred_on' => '2026-09-01', 'amount' => 500000, 'description' => 'Saldo inicial']);

        expect($this->bank->balance())->toBe(500000)
            ->and(fn () => $entry->update(['amount' => 1]))->toThrow(LogicException::class)
            ->and(fn () => $entry->delete())->toThrow(LogicException::class);

        $entry->reverse('Error de carga');
        expect($this->bank->balance())->toBe(0);
    });

    it('toda organización nueva tiene una Caja', function () {
        expect(MoneyAccount::query()->where('name', 'Caja')->exists())->toBeTrue();
    });
});

describe('registrar pago', function () {
    it('imputa del vencimiento más viejo al más nuevo entre hermanos y deja el resto como saldo a favor', function () {
        $payment = pay(500000);

        expect($payment->allocations->pluck('amount')->all())->toBe([150000, 150000, 150000, 50000])
            ->and(chargeOf($this->mateo, '2026-08-01')->status()->value)->toBe('pagado')
            ->and(chargeOf($this->sofia, '2026-08-01')->status()->value)->toBe('pagado')
            ->and(chargeOf($this->sofia, '2026-09-01')->pendingAmount())->toBe(100000)
            ->and($this->family->credit())->toBe(0)
            ->and($payment->receiptLabel())->toBe('000001')
            ->and($this->bank->balance())->toBe(500000);
    });

    it('lo pagado de más queda como saldo a favor y se aplica solo a la próxima cuota', function () {
        pay(700000);
        expect($this->family->credit())->toBe(100000);

        issueMonth($this->jakare, '2026-10');

        expect(chargeOf($this->mateo, '2026-10-01')->pendingAmount())->toBe(50000)
            ->and($this->family->credit())->toBe(0);
    });

    it('el recibo no cambia cuando el saldo a favor se aplica después', function () {
        $payment = pay(700000);
        issueMonth($this->jakare, '2026-10');

        $payment = $payment->fresh('allocations');
        expect($payment->creditGenerated())->toBe(100000)
            ->and($payment->credit())->toBe(0)
            ->and($payment->originalAllocations())->toHaveCount(4)
            ->and($payment->allocations)->toHaveCount(5)
            ->and($payment->allocations->last()->from_credit)->toBeTrue();
    });

    it('respeta la imputación elegida y no deja pasar lo pendiente', function () {
        $september = chargeOf($this->sofia, '2026-09-01');

        $payment = pay(150000, [$september->id => 150000]);
        expect($payment->allocations->sole()->charge_id)->toBe($september->id)
            ->and(chargeOf($this->mateo, '2026-08-01')->status()->value)->not->toBe('pagado');

        expect(fn () => pay(200000, [chargeOf($this->mateo, '2026-08-01')->id => 200000]))
            ->toThrow(ValidationException::class);
    });

    it('un pago parcial deja el cargo pendiente con saldo', function () {
        pay(100000);

        $august = chargeOf($this->mateo, '2026-08-01');
        expect($august->paidAmount())->toBe(100000)
            ->and($august->pendingAmount())->toBe(50000)
            ->and($august->status()->value)->toBe('vencido');
    });

    it('recibos correlativos sin huecos', function () {
        expect([pay(1000)->receipt_number, pay(1000)->receipt_number, pay(1000)->receipt_number])->toBe([1, 2, 3]);
    });
});

describe('pronto pago', function () {
    beforeEach(function () {
        $rule = DiscountRule::factory()->for($this->jakare)->create([
            'type' => DiscountType::EarlyPayment, 'name' => 'Pronto pago', 'percent' => 10, 'sibling_position' => null, 'until_day' => 5,
        ]);
        $rule->feeConcepts()->attach($this->monthly);
        $this->september = chargeOf($this->mateo, '2026-09-01');
    });

    it('a tiempo y saldando: se descuenta y queda en el detalle', function () {
        pay(135000, [$this->september->id => 135000], '2026-09-05');

        $charge = $this->september->fresh();
        expect($charge->status()->value)->toBe('pagado')
            ->and($charge->activeAllocations()->sole()->early_payment_discount)->toBe(15000)
            ->and($charge->activeAllocations()->sole()->early_payment_label)->toBe('Pronto pago −10 %');
    });

    it('tarde o sin saldar no hay descuento', function () {
        pay(135000, [$this->september->id => 135000], '2026-09-06');
        expect($this->september->fresh()->pendingAmount())->toBe(15000);

        $sofia = chargeOf($this->sofia, '2026-09-01');
        pay(100000, [$sofia->id => 100000], '2026-09-02');
        expect($sofia->fresh()->activeAllocations()->sole()->early_payment_discount)->toBe(0);
    });
});

describe('anular pago', function () {
    it('los cargos vuelven a pendientes, contra-movimiento y se revierte el saldo a favor aplicado', function () {
        $payment = pay(700000);
        issueMonth($this->jakare, '2026-10');

        app(VoidPayment::class)->handle($payment, 'Transferencia rechazada', memberOf($this->jakare));

        expect(chargeOf($this->mateo, '2026-08-01')->pendingAmount())->toBe(150000)
            ->and(chargeOf($this->mateo, '2026-10-01')->pendingAmount())->toBe(150000)
            ->and($this->bank->balance())->toBe(0)
            ->and($this->family->credit())->toBe(0)
            ->and(fn () => app(VoidPayment::class)->handle($payment->fresh(), 'otra vez', memberOf($this->jakare)))->toThrow(ValidationException::class);
    });

    it('un pago no se edita ni se borra', function () {
        $payment = pay(1000);

        expect(fn () => $payment->update(['amount' => 1]))->toThrow(LogicException::class)
            ->and(fn () => $payment->delete())->toThrow(LogicException::class);
    });
});

describe('recibo', function () {
    it('PDF por link firmado; alterado o vencido da 403', function () {
        $payment = pay(300000);
        $url = ReceiptController::signedUrl($payment);

        $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(str_replace("recibos/{$payment->id}", 'recibos/999', $url))->assertForbidden();

        $this->travel(31)->minutes();
        $this->get($url)->assertForbidden();
    });

    it('dice "Alumno" (o lo que usen) en vez de "Jugador"', function () {
        $this->jakare->update(['terminology' => ['student' => 'Alumno']]);
        $payment = pay(150000);

        $html = view('receipts.show', [
            'payment' => $payment->load(['organization', 'family', 'guardian', 'moneyAccount', 'allocations.charge.student']),
            'organization' => $payment->organization,
            'allocations' => $payment->originalAllocations(),
            'credit' => $payment->creditGenerated(),
            'money' => fn (int $amount) => Money::pyg($amount),
        ])->render();

        expect($html)->toContain('<th>Alumno</th>')->not->toContain('Jugador');
    });

    it('la columna nombra a los chicos según su género: Jugadora si son todas chicas', function () {
        $render = fn (Payment $payment) => view('receipts.show', ReceiptController::viewData(
            $payment->load(['organization', 'family', 'guardian', 'moneyAccount', 'allocations.charge.student']),
        ))->render();

        // Sin género cargado: la palabra del club.
        $charge = fn (Student $student) => chargeOf($student, '2026-08-01');
        $sofia = pay($charge($this->sofia)->pendingAmount(), [$charge($this->sofia)->id => $charge($this->sofia)->pendingAmount()]);
        expect($render($sofia))->toContain('<th>Jugador</th>');

        $this->sofia->update(['gender' => Gender::Female]);
        expect($render($sofia->fresh()))->toContain('<th>Jugadora</th>');

        // Mateo y Sofía en el mismo recibo: masculino genérico.
        $this->mateo->update(['gender' => Gender::Male]);
        $september = fn (Student $student) => chargeOf($student, '2026-09-01');
        $amounts = [$september($this->mateo)->id => $september($this->mateo)->pendingAmount(), $september($this->sofia)->id => $september($this->sofia)->pendingAmount()];
        $both = pay(array_sum($amounts), $amounts);
        expect($render($both))->toContain('<th>Jugador</th>');

        // Con "Alumna" como palabra del club y un varón: Alumno.
        $this->jakare->update(['terminology' => ['student' => 'Alumna']]);
        expect($render($both->fresh()))->toContain('<th>Alumno</th>');
    });

    it('monto en letras', function () {
        expect(Money::pyg(150000)->inWords())->toBe('ciento cincuenta mil guaraníes')
            ->and(Money::pyg(21500)->inWords())->toBe('veintiún mil quinientos guaraníes')
            ->and(Money::pyg(1250000)->inWords())->toBe('un millón doscientos cincuenta mil guaraníes');
    });
});
