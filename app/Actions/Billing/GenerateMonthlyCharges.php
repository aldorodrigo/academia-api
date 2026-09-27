<?php

namespace App\Actions\Billing;

use App\Models\Charge;
use App\Models\Enrollment;
use App\Models\Family;
use App\Models\FeeConcept;
use App\Models\Organization;
use App\Models\Season;
use App\Models\Tariff;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Cuota mensual automática para las inscripciones facturables de la temporada actual.
 *
 * Idempotente: lock en Redis por organización y período + índice único
 * (inscripción + concepto + período). Correrla dos veces no cobra dos veces.
 */
class GenerateMonthlyCharges
{
    public function __construct(
        private CurrentOrganization $current,
        private DiscountCalculator $discounts,
        private IssueCharge $issue,
        private ApplyCredit $applyCredit,
    ) {}

    /**
     * @param  bool  $dryRun  solo cuenta (vista previa del panel)
     * @return array{created: int, existing: int, full_scholarship: int, without_tariff: list<string>, out_of_season: bool}
     */
    public function handle(Organization $organization, CarbonImmutable $period, bool $dryRun = false, ?int $createdBy = null): array
    {
        $period = $period->startOfMonth();

        return $this->current->run($organization, function (Organization $organization) use ($period, $dryRun, $createdBy) {
            if ($dryRun) {
                return $this->generate($organization, $period, true, $createdBy);
            }

            $lock = Cache::lock("charges:{$organization->id}:{$period->format('Y-m')}", 600);

            if (! $lock->get()) {
                throw new RuntimeException('Ya se están generando las cuotas de ese mes.');
            }

            try {
                return $this->generate($organization, $period, false, $createdBy);
            } finally {
                $lock->release();
            }
        });
    }

    /**
     * @return array{created: int, existing: int, full_scholarship: int, without_tariff: list<string>, out_of_season: bool}
     */
    private function generate(Organization $organization, CarbonImmutable $period, bool $dryRun, ?int $createdBy): array
    {
        $summary = ['created' => 0, 'existing' => 0, 'full_scholarship' => 0, 'without_tariff' => [], 'out_of_season' => false];

        $season = Season::currentOrNull();
        $concept = FeeConcept::monthlyFee($organization);

        if ($season === null || $concept === null
            || $period->lt($season->starts_on->startOfMonth()) || $period->gt($season->ends_on)) {
            return [...$summary, 'out_of_season' => true];
        }

        $enrollments = Enrollment::query()->billable()->with(['student', 'group', 'organization'])->get();
        $families = [];

        foreach ($enrollments as $enrollment) {
            $key = "enr:{$enrollment->id}:con:{$concept->id}:per:{$period->format('Y-m')}";

            if (Charge::query()->where('unique_key', $key)->exists()) {
                $summary['existing']++;

                continue;
            }

            $tariff = Tariff::applicable($concept, $season, $enrollment->group, $period);

            if ($tariff === null) {
                $summary['without_tariff'][] = $enrollment->group->name;

                continue;
            }

            if ($this->discounts->scholarshipFor($enrollment, $period)?->isTotal()) {
                $summary['full_scholarship']++;

                continue;
            }

            if ($dryRun) {
                $summary['created']++;

                continue;
            }

            $discounts = $this->discounts->calculate($enrollment, $concept, $period, $tariff->amount);

            try {
                $this->issue->handle([
                    'student_id' => $enrollment->student_id,
                    'enrollment_id' => $enrollment->id,
                    'group_id' => $enrollment->group_id,
                    'fee_concept_id' => $concept->id,
                    'tariff_id' => $tariff->id,
                    'period' => $period->toDateString(),
                    'description' => 'Cuota '.$period->locale('es')->translatedFormat('F Y'),
                    'base_amount' => $tariff->amount,
                    'issued_on' => $organization->today()->toDateString(),
                    'due_on' => DueDate::forPeriod($organization, $period)->toDateString(),
                    'unique_key' => $key,
                    'created_by' => $createdBy,
                ], $discounts['adjustments']);

                $summary['created']++;
                $families[] = $enrollment->student->family_id;
            } catch (UniqueConstraintViolationException) {
                $summary['existing']++;
            }
        }

        $summary['without_tariff'] = array_values(array_unique($summary['without_tariff']));

        // El saldo a favor se aplica solo al próximo cargo.
        Family::query()->whereKey(array_unique(array_filter($families)))
            ->get()
            ->each(fn (Family $family) => $this->applyCredit->forFamily($family));

        return $summary;
    }
}
