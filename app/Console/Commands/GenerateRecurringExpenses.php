<?php

namespace App\Console\Commands;

use App\Actions\Treasury\ExpenseLedger;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('expenses:generate {--organization= : slug de una organización} {--period= : mes (AAAA-MM); por defecto el actual en la fecha local}')]
#[Description('Genera los gastos pendientes del mes de los gastos recurrentes (idempotente)')]
class GenerateRecurringExpenses extends Command
{
    public function handle(ExpenseLedger $ledger): int
    {
        $organizations = Organization::query()->active()
            ->when($this->option('organization'), fn ($query, string $slug) => $query->where('slug', $slug))
            ->get();

        foreach ($organizations as $organization) {
            $period = $this->option('period')
                ? CarbonImmutable::createFromFormat('!Y-m', $this->option('period'))
                : $organization->today()->startOfMonth();

            $summary = $ledger->generateRecurring($organization, $period);
            $this->line("{$organization->name} {$period->format('Y-m')}: {$summary['created']} gastos generados, {$summary['existing']} ya existían");
        }

        return self::SUCCESS;
    }
}
