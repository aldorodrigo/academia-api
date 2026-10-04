<?php

namespace App\Filament\Resources\MoneyAccounts\Pages;

use App\Filament\Resources\MoneyAccounts\MoneyAccountResource;
use App\Models\LedgerEntry;
use App\Models\MoneyAccount;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;

class ListMoneyAccounts extends ListRecords
{
    protected static string $resource = MoneyAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // El saldo inicial es el primer movimiento (el saldo nunca se edita).
            CreateAction::make()->using(fn (array $data) => DB::transaction(function () use ($data) {
                $account = MoneyAccount::query()->create(collect($data)->only(['name', 'type', 'transfer_details'])->all());
                $opening = (int) ($data['opening_balance'] ?? 0);

                if ($opening > 0) {
                    LedgerEntry::query()->create([
                        'money_account_id' => $account->id,
                        'occurred_on' => now()->toDateString(),
                        'amount' => $opening,
                        'description' => 'Saldo inicial',
                        'created_by' => auth()->id(),
                    ]);
                }

                return $account;
            })),
        ];
    }
}
