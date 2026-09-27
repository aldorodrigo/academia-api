<?php

namespace App\Reports;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\LedgerEntry;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Carbon\CarbonImmutable;

/**
 * Balance del período, sobre el libro mayor (así cierra siempre:
 * inicial + ingresos − gastos + otros = final).
 *
 * - Ingresos: movimientos de pagos, repartidos por concepto según lo imputado en el
 *   recibo (lo no imputado como "Saldo a favor"). Una anulación resta en su período.
 * - Gastos: movimientos de gastos, por categoría.
 * - Transferencias: no cuentan. Saldos iniciales y ajustes: "Otros movimientos".
 */
class BalanceReport extends Report
{
    public function __construct(Organization $organization, private CarbonImmutable $from, private CarbonImmutable $to)
    {
        parent::__construct($organization);
    }

    public static function key(): string
    {
        return 'balance';
    }

    public function title(): string
    {
        return "Balance del {$this->from->format('d/m/Y')} al {$this->to->format('d/m/Y')}";
    }

    public function parameters(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }

    public function data(): array
    {
        $entries = LedgerEntry::query()
            ->whereDate('occurred_on', '>=', $this->from)
            ->whereDate('occurred_on', '<=', $this->to)
            ->get();

        $income = [];
        $expenses = [];
        $other = 0;

        $payments = Payment::query()->whereKey($entries->where('source_type', (new Payment)->getMorphClass())->pluck('source_id'))
            ->with('allocations.charge.feeConcept')->get()->keyBy('id');
        $expenseModels = Expense::query()->whereKey($entries->where('source_type', (new Expense)->getMorphClass())->pluck('source_id'))
            ->with('category')->get()->keyBy('id');

        foreach ($entries as $entry) {
            $sign = $entry->amount >= 0 ? 1 : -1;

            if ($entry->source_type === (new Payment)->getMorphClass() && ($payment = $payments->get($entry->source_id))) {
                foreach ($payment->originalAllocations() as $allocation) {
                    /** @var PaymentAllocation $allocation */
                    $label = $allocation->charge->feeConcept->name;
                    $income[$label] = ($income[$label] ?? 0) + $sign * $allocation->amount;
                }
                if ($payment->creditGenerated() > 0) {
                    $income['Saldo a favor'] = ($income['Saldo a favor'] ?? 0) + $sign * $payment->creditGenerated();
                }
            } elseif ($entry->source_type === (new Expense)->getMorphClass() && ($expense = $expenseModels->get($entry->source_id))) {
                $label = $expense->category->name;
                $expenses[$label] = ($expenses[$label] ?? 0) - $entry->amount;
            } elseif ($entry->source_type === null) {
                $other += $entry->amount;
            }
        }

        $lines = fn (array $totals) => collect($totals)->filter()->sortDesc()
            ->map(fn (int $amount, string $label) => ['label' => $label, 'amount' => $amount])->values()->all();

        $opening = (int) LedgerEntry::query()->whereDate('occurred_on', '<', $this->from)->sum('amount');
        $closing = (int) LedgerEntry::query()->whereDate('occurred_on', '<=', $this->to)->sum('amount');

        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'income' => ['total' => array_sum($income), 'lines' => $lines($income)],
            'expenses' => ['total' => array_sum($expenses), 'lines' => $lines($expenses)],
            'other' => $other,
            'accounts' => MoneyAccount::query()->orderBy('name')->get()->map(fn (MoneyAccount $account) => [
                'name' => $account->name,
                'balance' => (int) $account->entries()->whereDate('occurred_on', '<=', $this->to)->sum('amount'),
            ])->values()->all(),
            'pending_expenses' => (int) Expense::query()->where('status', ExpenseStatus::Pending)
                ->whereDate('due_on', '>=', $this->from)->whereDate('due_on', '<=', $this->to)->sum('amount'),
        ];
    }

    public function sections(): array
    {
        $data = $this->data();
        $rows = fn (array $lines) => array_map(fn (array $line) => [$line['label'], $line['amount']], $lines);

        return [
            ['title' => 'Resumen', 'headers' => ['', 'Monto'], 'money' => [1], 'rows' => array_values(array_filter([
                ['Saldo inicial', $data['opening_balance']],
                ['Ingresos', $data['income']['total']],
                ['Gastos', -$data['expenses']['total']],
                $data['other'] ? ['Otros movimientos (saldos iniciales, ajustes)', $data['other']] : null,
                ['Saldo final', $data['closing_balance']],
                $data['pending_expenses'] ? ['Gastos pendientes de pago', $data['pending_expenses']] : null,
            ]))],
            ['title' => 'Ingresos', 'headers' => ['Concepto', 'Monto'], 'money' => [1], 'rows' => $rows($data['income']['lines'])],
            ['title' => 'Gastos', 'headers' => ['Categoría', 'Monto'], 'money' => [1], 'rows' => $rows($data['expenses']['lines'])],
            ['title' => 'Cuentas al cierre', 'headers' => ['Cuenta', 'Saldo'], 'money' => [1],
                'rows' => array_map(fn (array $a) => [$a['name'], $a['balance']], $data['accounts'])],
        ];
    }
}
