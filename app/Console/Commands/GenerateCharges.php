<?php

namespace App\Console\Commands;

use App\Actions\Billing\GenerateSeasonCharges;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('charges:generate {--organization= : slug de una organización} {--date= : fecha (AAAA-MM-DD); por defecto hoy en la fecha local}')]
#[Description('Emite las cuotas ya empezadas de las temporadas vigentes con plan de cobro (idempotente)')]
class GenerateCharges extends Command
{
    public function handle(GenerateSeasonCharges $generate): int
    {
        $organizations = Organization::query()->active()
            ->when($this->option('organization'), fn ($query, string $slug) => $query->where('slug', $slug))
            ->get();

        foreach ($organizations as $organization) {
            $on = $this->option('date')
                ? CarbonImmutable::createFromFormat('!Y-m-d', $this->option('date'))
                : $organization->today();

            try {
                $summary = $generate->handle($organization, $on);
            } catch (Throwable $e) {
                $this->error("{$organization->name}: {$e->getMessage()}");

                continue;
            }

            $this->line(sprintf(
                '%s %s: %d creadas, %d ya existían, %d becas totales%s',
                $organization->name,
                $on->toDateString(),
                $summary['created'],
                $summary['existing'],
                $summary['full_scholarship'],
                $summary['without_tariff'] ? ', sin tarifa: '.implode(', ', $summary['without_tariff']) : '',
            ));
        }

        return self::SUCCESS;
    }
}
