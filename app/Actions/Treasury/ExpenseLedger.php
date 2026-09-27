<?php

namespace App\Actions\Treasury;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\LedgerEntry;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gastos: registrar (pagado en el momento), pagar un pendiente, anular y generar
 * los pendientes de los recurrentes. Cada pago es un movimiento de salida.
 */
class ExpenseLedger
{
    public function __construct(private CurrentOrganization $current) {}

    /**
     * Gasto suelto, pagado en el momento.
     *
     * @param  array{expense_category_id: int, supplier_id?: ?int, description: string, amount: int, attachment?: ?string}  $data
     */
    public function register(array $data, MoneyAccount $account, CarbonImmutable $paidOn, ?User $by = null): Expense
    {
        $this->assertAmount((int) $data['amount']);

        return DB::transaction(function () use ($data, $account, $paidOn, $by) {
            $expense = Expense::query()->create([
                ...$data,
                'organization_id' => $account->organization_id,
                'status' => ExpenseStatus::Pending,
                'created_by' => $by?->id,
            ]);

            return $this->pay($expense, $account, $paidOn, $by);
        });
    }

    /**
     * Paga un gasto pendiente: sale de la cuenta en la fecha indicada.
     */
    public function pay(Expense $expense, MoneyAccount $account, CarbonImmutable $paidOn, ?User $by = null): Expense
    {
        // Siempre sobre lo guardado (no sobre cambios en memoria).
        $expense->refresh();

        if ($expense->status !== ExpenseStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'El gasto ya está pagado o anulado.']);
        }

        return DB::transaction(function () use ($expense, $account, $paidOn, $by) {
            $expense->update([
                'status' => ExpenseStatus::Paid,
                'money_account_id' => $account->id,
                'paid_on' => $paidOn->toDateString(),
            ]);

            LedgerEntry::query()->create([
                'organization_id' => $expense->organization_id,
                'money_account_id' => $account->id,
                'occurred_on' => $paidOn->toDateString(),
                'amount' => -$expense->amount,
                'description' => $expense->description.($expense->supplier ? " · {$expense->supplier->name}" : ''),
                'source_type' => $expense->getMorphClass(),
                'source_id' => $expense->id,
                'created_by' => $by?->id,
            ]);

            return $expense;
        });
    }

    /**
     * Anula con motivo. Si estaba pagado, contra-movimiento en la cuenta.
     */
    public function void(Expense $expense, string $reason, User $by): Expense
    {
        $expense->refresh();

        if ($expense->status === ExpenseStatus::Voided) {
            throw ValidationException::withMessages(['reason' => 'El gasto ya está anulado.']);
        }
        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => 'Indicá el motivo de la anulación.']);
        }

        return DB::transaction(function () use ($expense, $reason, $by) {
            $expense->ledgerEntries()->whereNull('reverses_id')->whereDoesntHave('reversedBy')->get()
                ->each(fn (LedgerEntry $entry) => $entry->reverse("Anulación: {$expense->description}", $by->id));

            $expense->update([
                'status' => ExpenseStatus::Voided,
                'voided_at' => now(),
                'void_reason' => trim($reason),
                'voided_by' => $by->id,
            ]);

            return $expense;
        });
    }

    /**
     * Gastos pendientes del mes para los recurrentes vigentes. Idempotente.
     *
     * @return array{created: int, existing: int}
     */
    public function generateRecurring(Organization $organization, CarbonImmutable $period, bool $dryRun = false): array
    {
        $period = $period->startOfMonth();

        return $this->current->run($organization, function () use ($period, $dryRun) {
            $summary = ['created' => 0, 'existing' => 0];

            foreach (RecurringExpense::query()->with('supplier')->get() as $recurring) {
                if (! $recurring->appliesTo($period)) {
                    continue;
                }

                if (Expense::query()->where('recurring_expense_id', $recurring->id)->whereDate('period', $period)->exists()) {
                    $summary['existing']++;

                    continue;
                }

                if (! $dryRun) {
                    try {
                        Expense::query()->create([
                            'expense_category_id' => $recurring->expense_category_id,
                            'supplier_id' => $recurring->supplier_id,
                            'money_account_id' => $recurring->money_account_id,
                            'description' => $recurring->description.' '.$period->locale('es')->translatedFormat('F Y'),
                            'amount' => $recurring->amount,
                            'due_on' => $period->setDay(min($recurring->day_of_month, $period->daysInMonth))->toDateString(),
                            'status' => ExpenseStatus::Pending,
                            'recurring_expense_id' => $recurring->id,
                            'period' => $period->toDateString(),
                        ]);
                    } catch (UniqueConstraintViolationException) {
                        $summary['existing']++;

                        continue;
                    }
                }

                $summary['created']++;
            }

            return $summary;
        });
    }

    private function assertAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'El monto tiene que ser mayor a cero.']);
        }
    }
}
