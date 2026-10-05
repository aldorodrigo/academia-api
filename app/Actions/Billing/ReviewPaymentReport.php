<?php

namespace App\Actions\Billing;

use App\Enums\MoneyAccountType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentReportStatus;
use App\Models\Guardian;
use App\Models\MoneyAccount;
use App\Models\PaymentReport;
use App\Models\User;
use App\Notifications\PaymentReportReviewed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Aprobar un comprobante registra el pago por transferencia (con su recibo)
 * imputado a las cuotas elegidas que sigan pendientes; rechazarlo deja el motivo.
 * En los dos casos se avisa al tutor.
 */
class ReviewPaymentReport
{
    public function __construct(private RegisterPayment $register) {}

    public function approve(
        PaymentReport $report,
        User $by,
        ?MoneyAccount $account = null,
        ?CarbonImmutable $receivedOn = null,
        ?int $amount = null,
    ): PaymentReport {
        $report = DB::transaction(function () use ($report, $by, $account, $receivedOn, $amount) {
            $report = $this->lockPending($report);
            $family = $report->family;
            $receivedOn ??= CarbonImmutable::parse($report->paid_on);
            $amount ??= $report->amount;
            $account ??= $this->defaultAccount($report);

            $allocations = null;
            if ($report->charge_ids !== []) {
                $selected = $this->register->pendingCharges($family)->whereIn('id', $report->charge_ids)->values();
                $allocations = collect($this->register->plan($selected, $amount, $receivedOn))->pluck('amount', 'charge_id')->all();
            }

            $payment = $this->register->handle(
                $family,
                $account,
                $amount,
                PaymentMethod::Transfer,
                $receivedOn,
                $by,
                payer: $report->registered_by_staff
                    ? $report->guardian
                    : Guardian::query()->where('family_id', $family->id)->where('user_id', $report->user_id)->first(),
                allocations: $allocations,
                reference: $report->reference,
                notes: $report->registered_by_staff
                    ? "Transferencia registrada por {$report->user->name} desde la app."
                    : 'Comprobante informado desde la app.',
            );

            $report->update([
                'status' => PaymentReportStatus::Approved,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'payment_id' => $payment->id,
                'money_account_id' => $report->money_account_id ?? $account->id,
            ]);

            return $report;
        });

        $report->load(['payment', 'family']);
        if ($report->registered_by_staff) {
            // La familia recibe el recibo; quien la registró se entera si la aprobó otro.
            Notification::send(CollectCashPayment::familyUsers($report->family), new PaymentReportReviewed($report));
            if (! $report->user->is($by)) {
                $report->user->notify(new PaymentReportReviewed($report, registrant: true));
            }
        } else {
            $report->user->notify(new PaymentReportReviewed($report));
        }

        return $report;
    }

    public function reject(PaymentReport $report, User $by, string $reason): PaymentReport
    {
        $report = DB::transaction(function () use ($report, $by, $reason) {
            $report = $this->lockPending($report);
            $report->update([
                'status' => PaymentReportStatus::Rejected,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'rejection_reason' => trim($reason),
            ]);

            return $report;
        });

        // Si la registró el club, el motivo le llega a quien la registró (la familia no la informó).
        $report->user->notify(new PaymentReportReviewed($report->load('family'), registrant: $report->registered_by_staff));

        return $report;
    }

    /**
     * Relee con lock: dos personas no aprueban el mismo comprobante a la vez.
     */
    private function lockPending(PaymentReport $report): PaymentReport
    {
        $report = PaymentReport::query()->withoutGlobalScopes()->whereNull('deleted_at')->with(['family', 'user', 'guardian'])->lockForUpdate()->findOrFail($report->id);

        if (! $report->isPending()) {
            throw ValidationException::withMessages(['status' => 'Este comprobante ya fue revisado.']);
        }

        return $report;
    }

    /**
     * La cuenta informada (si sigue activa y es del club), si no la primera bancaria, si no cualquiera activa del club.
     */
    private function defaultAccount(PaymentReport $report): MoneyAccount
    {
        // Sin las cajas personales: una transferencia no entra en la caja de un técnico.
        $accounts = MoneyAccount::query()->withoutGlobalScopes()->club()
            ->where('organization_id', $report->organization_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $account = $accounts->firstWhere('id', $report->money_account_id)
            ?? $accounts->firstWhere('type', MoneyAccountType::Bank)
            ?? $accounts->first();

        if ($account === null) {
            throw ValidationException::withMessages(['money_account_id' => 'Creá una cuenta (caja o banco) para registrar el pago.']);
        }

        return $account;
    }
}
