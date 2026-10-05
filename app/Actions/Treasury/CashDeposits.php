<?php

namespace App\Actions\Treasury;

use App\Actions\Billing\PaymentReportAccess;
use App\Enums\CashDepositStatus;
use App\Models\CashDeposit;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Notifications\CashDepositReported;
use App\Notifications\CashDepositReviewed;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Depósitos de efectivo: quien cobra informa que dejó la plata de su caja en una cuenta del
 * club; queda por confirmar (la plata sigue en su caja) hasta que quien valida comprobantes
 * lo confirma (transferencia de su caja a esa cuenta, con `TransferFunds`) o lo rechaza.
 */
class CashDeposits
{
    public function __construct(private TransferFunds $transfers) {}

    /**
     * Lo que puede depositar: el saldo menos lo que ya está por confirmar.
     */
    public static function available(MoneyAccount $box): int
    {
        return $box->balance() - self::pendingAmount($box);
    }

    public static function pendingAmount(MoneyAccount $box): int
    {
        // Sin el scope de la organización, pero sin los retirados (soft delete).
        return (int) CashDeposit::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where('money_account_id', $box->id)->pending()->sum('amount');
    }

    public function submit(
        User $user,
        MoneyAccount $box,
        MoneyAccount $to,
        int $amount,
        CarbonImmutable $depositedOn,
        ?string $reference = null,
        ?string $notes = null,
    ): CashDeposit {
        if ($to->isCashBox() || ! $to->is_active || $to->organization_id !== $box->organization_id) {
            throw ValidationException::withMessages(['money_account_id' => 'Elegí una cuenta del club.']);
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'El monto tiene que ser mayor a cero.']);
        }

        $deposit = DB::transaction(function () use ($user, $box, $to, $amount, $depositedOn, $reference, $notes) {
            // Lock de la caja: dos depósitos a la vez no superan lo disponible.
            MoneyAccount::query()->withoutGlobalScopes()->whereKey($box->id)->lockForUpdate()->first();

            $available = self::available($box);
            if ($amount > $available) {
                throw ValidationException::withMessages(['amount' => 'Tenés '.Money::pyg(max(0, $available))->format().' para depositar.']);
            }

            return CashDeposit::query()->create([
                'organization_id' => $box->organization_id,
                'money_account_id' => $box->id,
                'user_id' => $user->id,
                'to_account_id' => $to->id,
                'amount' => $amount,
                'deposited_on' => $depositedOn->toDateString(),
                'reference' => filled($reference) ? trim($reference) : null,
                'notes' => filled($notes) ? trim($notes) : null,
            ]);
        });

        Notification::send(
            PaymentReportAccess::reviewers($box->organization)->reject(fn (User $reviewer) => $reviewer->is($user)),
            new CashDepositReported($deposit->load(['user', 'toAccount'])),
        );

        return $deposit;
    }

    /**
     * Retira un depósito propio por confirmar (soft delete).
     */
    public function withdraw(CashDeposit $deposit): void
    {
        DB::transaction(function () use ($deposit) {
            $this->lockPending($deposit)->delete();
        });
    }

    public function confirm(CashDeposit $deposit, User $by): CashDeposit
    {
        $deposit = DB::transaction(function () use ($deposit, $by) {
            $deposit = $this->lockPending($deposit);

            $transfer = $this->transfers->handle(
                $deposit->cashBox,
                $deposit->toAccount,
                $deposit->amount,
                CarbonImmutable::parse($deposit->deposited_on),
                collect(['Depósito de efectivo', $deposit->reference])->filter()->join(' · '),
                $by,
            );

            $deposit->update([
                'status' => CashDepositStatus::Confirmed,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'transfer_id' => $transfer->id,
            ]);

            return $deposit;
        });

        $deposit->user->notify(new CashDepositReviewed($deposit));

        return $deposit;
    }

    public function reject(CashDeposit $deposit, User $by, string $reason): CashDeposit
    {
        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => 'Contale por qué no lo confirmás.']);
        }

        $deposit = DB::transaction(function () use ($deposit, $by, $reason) {
            $deposit = $this->lockPending($deposit);
            $deposit->update([
                'status' => CashDepositStatus::Rejected,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'rejection_reason' => trim($reason),
            ]);

            return $deposit;
        });

        $deposit->user->notify(new CashDepositReviewed($deposit));

        return $deposit;
    }

    /**
     * Relee con lock: dos personas no confirman el mismo depósito a la vez.
     */
    private function lockPending(CashDeposit $deposit): CashDeposit
    {
        $deposit = CashDeposit::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->with(['cashBox', 'toAccount', 'user'])
            ->lockForUpdate()
            ->findOrFail($deposit->id);

        if (! $deposit->isPending()) {
            throw ValidationException::withMessages(['status' => 'Este depósito ya fue revisado.']);
        }

        return $deposit;
    }
}
