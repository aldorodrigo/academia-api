<?php

namespace App\Actions\Billing;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\Family;
use App\Models\Organization;
use App\Models\Season;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Emite las cuotas de las temporadas con plan de cobro (corre todos los días).
 *
 * Por defecto recorre las temporadas vigentes y emite, para cada inscripción activa o becada,
 * los períodos ya empezados (o toda la temporada si sus cuotas se crean al inscribir).
 * Desde el panel se puede elegir una temporada y emitir "hasta hoy" o "toda la temporada".
 *
 * Idempotente: lock por organización y fecha + clave única por inscripción y período.
 */
class GenerateSeasonCharges
{
    public function __construct(
        private CurrentOrganization $current,
        private IssueSeasonCharges $issue,
        private ApplyCredit $applyCredit,
    ) {}

    /**
     * @param  bool  $dryRun  solo cuenta (vista previa del panel)
     * @return array{created: int, existing: int, full_scholarship: int, without_tariff: list<string>, amount: int, seasons: int}
     */
    public function handle(Organization $organization, ?CarbonImmutable $on = null, bool $dryRun = false, ?int $createdBy = null, ?Season $season = null, bool $wholeSeason = false): array
    {
        return $this->current->run($organization, function (Organization $organization) use ($on, $dryRun, $createdBy, $season, $wholeSeason) {
            $on ??= $organization->today();

            if ($dryRun) {
                return $this->generate($on, true, $createdBy, $season, $wholeSeason);
            }

            $lock = Cache::lock("charges:{$organization->id}:{$on->toDateString()}", 600);

            if (! $lock->get()) {
                throw new RuntimeException('Ya se están generando las cuotas.');
            }

            try {
                return $this->generate($on, false, $createdBy, $season, $wholeSeason);
            } finally {
                $lock->release();
            }
        });
    }

    /**
     * @return array{created: int, existing: int, full_scholarship: int, without_tariff: list<string>, amount: int, seasons: int}
     */
    private function generate(CarbonImmutable $on, bool $dryRun, ?int $createdBy, ?Season $season, bool $wholeSeason): array
    {
        $summary = ['created' => 0, 'existing' => 0, 'full_scholarship' => 0, 'without_tariff' => [], 'amount' => 0, 'seasons' => 0];
        $seasons = $season !== null
            ? collect([$season])
            : Season::query()->active($on)->whereNotNull('fee_frequency')->get();
        $families = [];

        foreach ($seasons as $season) {
            if (! $season->hasFeePlan() || $season->chargesByAttendance()) {
                continue;
            }

            $summary['seasons']++;
            $until = $wholeSeason || $season->issue_upfront ? $season->ends_on : $on;

            $enrollments = Enrollment::query()
                ->where('season_id', $season->id)
                ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Scholarship])
                ->with(['student', 'group', 'season', 'organization'])
                ->get();

            foreach ($enrollments as $enrollment) {
                $result = $this->issue->forEnrollment($enrollment, $until, dryRun: $dryRun, createdBy: $createdBy, applyCredit: false);

                $summary['created'] += $result['created'];
                $summary['existing'] += $result['existing'];
                $summary['full_scholarship'] += $result['full_scholarship'];
                $summary['amount'] += $result['amount'];

                if ($result['without_tariff']) {
                    $summary['without_tariff'][] = $enrollment->group->name;
                }

                if ($result['created'] > 0) {
                    $families[] = $enrollment->student->family_id;
                }
            }
        }

        $summary['without_tariff'] = array_values(array_unique($summary['without_tariff']));

        if (! $dryRun) {
            // El saldo a favor se aplica solo a las cuotas nuevas.
            Family::query()->whereKey(array_unique(array_filter($families)))
                ->get()
                ->each(fn (Family $family) => $this->applyCredit->forFamily($family));
        }

        return $summary;
    }
}
