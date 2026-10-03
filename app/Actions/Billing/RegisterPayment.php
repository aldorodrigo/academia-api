<?php

namespace App\Actions\Billing;

use App\Actions\Lessons\ActivatePaidPacks;
use App\Enums\PaymentMethod;
use App\Models\Charge;
use App\Models\Family;
use App\Models\Guardian;
use App\Models\LedgerEntry;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registra un pago de una familia: lo imputa a sus cargos pendientes (por defecto
 * del vencimiento más viejo al más nuevo, entre todos los hijos), aplica el pronto
 * pago a los cargos que salda a tiempo, deja el resto como saldo a favor, crea el
 * movimiento en la caja o banco y numera el recibo.
 */
class RegisterPayment
{
    public function __construct(
        private EarlyPaymentDiscount $earlyPayment,
        private ActivatePaidPacks $activatePacks,
    ) {}

    /**
     * @param  array<int, int>|null  $allocations  cargo => monto elegido por el tesorero; null = automático
     */
    public function handle(
        Family $family,
        MoneyAccount $account,
        int $amount,
        PaymentMethod $method,
        CarbonImmutable $receivedOn,
        ?User $by = null,
        ?Guardian $payer = null,
        ?array $allocations = null,
        ?string $reference = null,
        ?string $notes = null,
    ): Payment {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'El monto tiene que ser mayor a cero.']);
        }

        $payment = DB::transaction(function () use ($family, $account, $amount, $method, $receivedOn, $by, $payer, $allocations, $reference, $notes) {
            // Lock de la organización: recibo correlativo sin huecos.
            Organization::query()->whereKey($family->organization_id)->lockForUpdate()->first();

            $payment = Payment::query()->create([
                'organization_id' => $family->organization_id,
                'family_id' => $family->id,
                'guardian_id' => $payer?->id,
                'money_account_id' => $account->id,
                'received_on' => $receivedOn->toDateString(),
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'receipt_number' => $this->nextReceiptNumber($family->organization_id),
                'notes' => $notes,
                'created_by' => $by?->id,
            ]);

            $plan = $this->plan($this->pendingCharges($family), $amount, $receivedOn, $allocations);

            foreach ($plan as $line) {
                $payment->allocations()->create([
                    'organization_id' => $family->organization_id,
                    ...$line,
                ]);
            }

            LedgerEntry::query()->create([
                'organization_id' => $family->organization_id,
                'money_account_id' => $account->id,
                'occurred_on' => $receivedOn->toDateString(),
                'amount' => $amount,
                'description' => "Recibo N° {$payment->receiptLabel()} · {$family->name}",
                'source_type' => $payment->getMorphClass(),
                'source_id' => $payment->id,
                'created_by' => $by?->id,
            ]);

            return $payment->load('allocations');
        });

        // Los paquetes de clases que quedaron pagados se activan.
        $this->activatePacks->forFamily($family);

        return $payment;
    }

    /**
     * Cargos pendientes de todos los hijos de la familia, del más viejo al más nuevo.
     *
     * @return Collection<int, Charge>
     */
    public function pendingCharges(Family $family): Collection
    {
        return Charge::query()->withoutGlobalScopes()
            ->where('organization_id', $family->organization_id)
            ->whereHas('student', fn ($query) => $query->withoutGlobalScopes()->where('family_id', $family->id))
            ->whereNull('voided_at')
            ->with(['allocations.payment', 'student', 'organization'])
            ->orderBy('due_on')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->filter(fn (Charge $charge) => $charge->pendingAmount() > 0)
            ->values();
    }

    /**
     * Qué se imputa a cada cargo (y el pronto pago si lo salda a tiempo).
     *
     * @param  Collection<int, Charge>  $charges
     * @param  array<int, int>|null  $chosen
     * @return list<array{charge_id: int, amount: int, early_payment_discount: int, early_payment_label: ?string}>
     */
    public function plan(Collection $charges, int $amount, CarbonImmutable $receivedOn, ?array $chosen = null): array
    {
        $remaining = $amount;
        $lines = [];

        if ($chosen !== null) {
            $unknown = array_diff(array_keys($chosen), $charges->modelKeys());
            if ($unknown !== []) {
                throw ValidationException::withMessages(['allocations' => 'Hay cargos que no son de esta familia o ya están pagados.']);
            }
            if (array_sum($chosen) > $amount) {
                throw ValidationException::withMessages(['allocations' => 'Lo imputado supera el monto del pago.']);
            }
        }

        foreach ($charges as $charge) {
            if ($remaining <= 0) {
                break;
            }
            if ($chosen !== null && ! isset($chosen[$charge->id])) {
                continue;
            }

            $pending = $charge->pendingAmount();
            $early = $this->earlyPayment->for($charge, $receivedOn, $pending);
            $toSettle = $pending - ($early['amount'] ?? 0);
            $wanted = $chosen !== null ? (int) $chosen[$charge->id] : $remaining;

            if ($chosen !== null && $wanted > $toSettle && $wanted > $pending) {
                throw ValidationException::withMessages(['allocations' => "El monto para \"{$charge->description}\" supera lo pendiente."]);
            }

            // El pronto pago solo vale si el pago salda el cargo.
            $settles = $early !== null && min($wanted, $remaining) >= $toSettle;
            $pay = $settles ? $toSettle : min($wanted, $remaining, $pending);

            if ($pay <= 0) {
                continue;
            }

            $lines[] = [
                'charge_id' => $charge->id,
                'amount' => $pay,
                'early_payment_discount' => $settles ? $early['amount'] : 0,
                'early_payment_label' => $settles ? $early['label'] : null,
            ];
            $remaining -= $pay;
        }

        return $lines;
    }

    private function nextReceiptNumber(int $organizationId): int
    {
        return 1 + (int) Payment::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->max('receipt_number');
    }
}
