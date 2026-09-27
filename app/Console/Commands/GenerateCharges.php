<?php

namespace App\Console\Commands;

use App\Actions\Billing\GenerateMonthlyCharges;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('charges:generate {--organization= : slug de una organización} {--period= : mes (AAAA-MM); por defecto el actual en la fecha local}')]
#[Description('Genera la cuota mensual de las inscripciones facturables (idempotente)')]
class GenerateCharges extends Command
{
    public function handle(GenerateMonthlyCharges $generate): int
    {
        $organizations = Organization::query()->active()
            ->when($this->option('organization'), fn ($query, string $slug) => $query->where('slug', $slug))
            ->get();

        foreach ($organizations as $organization) {
            $period = $this->option('period')
                ? CarbonImmutable::createFromFormat('!Y-m', $this->option('period'))
                : $organization->today()->startOfMonth();

            try {
                $summary = $generate->handle($organization, $period);
            } catch (Throwable $e) {
                $this->error("{$organization->name}: {$e->getMessage()}");

                continue;
            }

            $this->line(sprintf(
                '%s %s: %d creadas, %d ya existían, %d becas totales%s',
                $organization->name,
                $period->format('Y-m'),
                $summary['created'],
                $summary['existing'],
                $summary['full_scholarship'],
                $summary['without_tariff'] ? ', sin tarifa: '.implode(', ', $summary['without_tariff']) : '',
            ));
        }

        return self::SUCCESS;
    }
}
