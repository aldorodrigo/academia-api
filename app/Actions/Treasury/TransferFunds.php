<?php

namespace App\Actions\Treasury;

use App\Models\LedgerEntry;
use App\Models\MoneyAccount;
use App\Models\Transfer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transferencias entre cuentas: dos movimientos enlazados en la misma transacción.
 */
class TransferFunds
{
    public function handle(MoneyAccount $from, MoneyAccount $to, int $amount, CarbonImmutable $on, ?string $description = null, ?User $by = null): Transfer
    {
        if ($from->is($to)) {
            throw ValidationException::withMessages(['to_account_id' => 'Elegí una cuenta distinta a la de origen.']);
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'El monto tiene que ser mayor a cero.']);
        }

        return DB::transaction(function () use ($from, $to, $amount, $on, $description, $by) {
            $transfer = Transfer::query()->create([
                'organization_id' => $from->organization_id,
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => $amount,
                'transferred_on' => $on->toDateString(),
                'description' => $description,
                'created_by' => $by?->id,
            ]);

            foreach ([[$from, -$amount, "Transferencia a {$to->name}"], [$to, $amount, "Transferencia desde {$from->name}"]] as [$account, $signed, $text]) {
                LedgerEntry::query()->create([
                    'organization_id' => $from->organization_id,
                    'money_account_id' => $account->id,
                    'occurred_on' => $on->toDateString(),
                    'amount' => $signed,
                    'description' => $description ? "{$text} · {$description}" : $text,
                    'source_type' => $transfer->getMorphClass(),
                    'source_id' => $transfer->id,
                    'created_by' => $by?->id,
                ]);
            }

            return $transfer;
        });
    }

    public function void(Transfer $transfer, string $reason, User $by): Transfer
    {
        if ($transfer->isVoided()) {
            throw ValidationException::withMessages(['reason' => 'La transferencia ya está anulada.']);
        }
        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => 'Indicá el motivo de la anulación.']);
        }

        return DB::transaction(function () use ($transfer, $reason, $by) {
            $transfer->ledgerEntries()->whereNull('reverses_id')->whereDoesntHave('reversedBy')->get()
                ->each(fn (LedgerEntry $entry) => $entry->reverse('Anulación de transferencia', $by->id));

            $transfer->update(['voided_at' => now(), 'void_reason' => trim($reason), 'voided_by' => $by->id]);

            return $transfer;
        });
    }
}
