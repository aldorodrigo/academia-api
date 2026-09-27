<?php

namespace App\Actions\Billing;

use App\Enums\AttendanceStatus;
use App\Enums\DailyBasis;
use App\Enums\DailyGrouping;
use App\Enums\FeeFrequency;
use App\Enums\MidPeriod;
use App\Models\Attendance;
use App\Models\Charge;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\FeeConcept;
use App\Models\Organization;
use App\Models\Tariff;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Cuotas de una inscripción según el plan de cobro de su temporada.
 *
 * Desde el período de la fecha de inscripción (o el inicio de la temporada) hasta:
 *  - el fin de la temporada, si las cuotas se crean todas al inscribir;
 *  - los períodos ya empezados, si se crean al empezar cada período.
 *
 * Primer período a mitad de camino: proporcional, completo o desde el próximo (lo que se
 * eligió al inscribir o lo del plan). Idempotente por `unique_key`: nunca cobra dos veces
 * el mismo período. Las temporadas sin plan no generan.
 *
 * Cobro por clase asistida: la cuota de un período se crea cuando ya terminó y pasaron los
 * días en que el técnico puede corregir la asistencia; la cantidad es la de presentes.
 */
class IssueSeasonCharges
{
    public function __construct(
        private CurrentOrganization $current,
        private SeasonPeriods $periods,
        private DiscountCalculator $discounts,
        private IssueCharge $issue,
        private ApplyCredit $applyCredit,
    ) {}

    /**
     * @param  CarbonImmutable|null  $until  hasta qué fecha (por defecto según el plan)
     * @param  CarbonImmutable|null  $from  desde qué fecha, en lugar de la de inscripción (al reactivar: hoy)
     * @return array{created: int, existing: int, full_scholarship: int, without_tariff: bool, amount: int}
     */
    public function forEnrollment(Enrollment $enrollment, ?CarbonImmutable $until = null, ?CarbonImmutable $from = null, bool $dryRun = false, ?int $createdBy = null, bool $applyCredit = true): array
    {
        return $this->current->run($enrollment->organization, function (Organization $organization) use ($enrollment, $until, $from, $dryRun, $createdBy, $applyCredit) {
            $summary = ['created' => 0, 'existing' => 0, 'full_scholarship' => 0, 'without_tariff' => false, 'amount' => 0];
            $season = $enrollment->season;
            $concept = FeeConcept::monthlyFee($organization);

            if ($concept === null || ! $season->hasFeePlan() || ! $enrollment->isBillableStatus()) {
                return $summary;
            }

            $today = $organization->today();
            $enrolledOn = CarbonImmutable::parse($enrollment->enrolled_on ?? $today)->startOfDay();
            $start = ($from ?? $enrolledOn)->startOfDay()->max($season->starts_on);
            $until ??= $season->issue_upfront ? $season->ends_on : $today;
            $byAttendance = $season->chargesByAttendance();

            if ($byAttendance) {
                // Solo períodos cerrados (la asistencia ya no se corrige desde la app).
                $until = $until->min(self::lastClosedDay($today));
            }

            $existing = Charge::query()
                ->where('enrollment_id', $enrollment->id)
                ->whereNotNull('unique_key')
                ->pluck('unique_key')
                ->flip();

            foreach ($this->periods->for($season, $enrollment->group, $start, $until) as $period) {
                // En los períodos ya creados se cuenta; los futuros más allá de `until` no.
                if ($period->start->gt($until) || ($byAttendance && $period->end->gt($until))) {
                    continue;
                }

                $key = "enr:{$enrollment->id}:con:{$concept->id}:per:{$period->key()}";

                if ($existing->has($key)) {
                    $summary['existing']++;

                    continue;
                }

                $charge = $byAttendance
                    ? $this->attendanceChargeFor($enrollment, $period, $concept, $today)
                    : $this->chargeFor($enrollment, $period, $enrolledOn, $concept);

                if ($charge === null) {
                    continue;
                }

                if ($charge === 'without_tariff') {
                    $summary['without_tariff'] = true;

                    continue;
                }

                if ($this->discounts->scholarshipFor($enrollment, $period->start)?->isTotal()) {
                    $summary['full_scholarship']++;

                    continue;
                }

                $discounts = $this->discounts->calculate($enrollment, $concept, $period->start, $charge['base_amount']);

                if ($dryRun) {
                    $summary['created']++;
                    $summary['amount'] += $discounts['final'];

                    continue;
                }

                try {
                    $this->issue->handle([
                        ...$charge,
                        'organization_id' => $organization->id,
                        'student_id' => $enrollment->student_id,
                        'enrollment_id' => $enrollment->id,
                        'group_id' => $enrollment->group_id,
                        'season_id' => $season->id,
                        'fee_concept_id' => $concept->id,
                        'period' => $period->start->startOfMonth()->toDateString(),
                        'period_start' => $period->start->toDateString(),
                        'period_end' => $period->end->toDateString(),
                        'issued_on' => $today->toDateString(),
                        'due_on' => $charge['due_on'],
                        'unique_key' => $key,
                        'created_by' => $createdBy ?? auth()->id(),
                    ], $discounts['adjustments']);

                    $summary['created']++;
                    $summary['amount'] += $discounts['final'];
                } catch (UniqueConstraintViolationException) {
                    $summary['existing']++;
                }
            }

            // El saldo a favor de la familia se aplica solo a las cuotas nuevas.
            if ($applyCredit && ! $dryRun && $summary['created'] > 0 && $enrollment->student->family !== null) {
                $this->applyCredit->forFamily($enrollment->student->family);
            }

            return $summary;
        });
    }

    /**
     * Último día de los períodos que ya se pueden cobrar por asistencia.
     */
    public static function lastClosedDay(CarbonImmutable $today): CarbonImmutable
    {
        return $today->subDays(ClassSession::EDITABLE_DAYS + 1);
    }

    /**
     * Cuota por clases asistidas: presentes del alumno en su grupo dentro del período.
     * Vence `due_days` después de crearse (el período ya terminó). Null sin clases asistidas.
     *
     * @return array{due_on: string, tariff_id: int, base_amount: int, quantity: int, unit_amount: int, description: string}|'without_tariff'|null
     */
    private function attendanceChargeFor(Enrollment $enrollment, BillingPeriod $period, FeeConcept $concept, CarbonImmutable $today): array|string|null
    {
        $season = $enrollment->season;
        $tariff = Tariff::applicable($concept, $season, $enrollment->group, $period->start);

        if ($tariff === null) {
            return 'without_tariff';
        }

        $quantity = Attendance::query()
            ->where('student_id', $enrollment->student_id)
            ->where('status', AttendanceStatus::Present)
            ->whereHas('classSession', fn ($query) => $query
                ->where('group_id', $enrollment->group_id)
                ->whereDate('date', '>=', $period->start->toDateString())
                ->whereDate('date', '<=', $period->end->toDateString()))
            ->count();

        if ($quantity === 0) {
            return null;
        }

        $shown = new BillingPeriod($period->start, $period->end, $period->dueOn, $quantity);
        $wholeMonth = ($season->daily_grouping ?? DailyGrouping::Month) === DailyGrouping::Month;

        return [
            'due_on' => $today->addDays($season->due_days)->toDateString(),
            'tariff_id' => $tariff->id,
            'base_amount' => $quantity * $tariff->amount,
            'quantity' => $quantity,
            'unit_amount' => $tariff->amount,
            'description' => $shown->description($season->fee_frequency, DailyBasis::Attendance, $wholeMonth),
        ];
    }

    /**
     * Monto y descripción de la cuota del período; null si no se cobra (desde el próximo
     * período, o sin días de entrenamiento), 'without_tariff' si no hay tarifa.
     *
     * @return array{due_on: string, tariff_id: int, base_amount: int, quantity: ?int, unit_amount: ?int, description: string}|'without_tariff'|null
     */
    private function chargeFor(Enrollment $enrollment, BillingPeriod $period, CarbonImmutable $enrolledOn, FeeConcept $concept): array|string|null
    {
        $season = $enrollment->season;
        $tariff = Tariff::applicable($concept, $season, $enrollment->group, $period->start);

        if ($tariff === null) {
            return 'without_tariff';
        }

        $midPeriod = $period->contains($enrolledOn) && $enrolledOn->gt($period->start)
            ? $enrollment->midPeriod()
            : null;

        if ($midPeriod === MidPeriod::Next) {
            return null;
        }

        $daily = $season->fee_frequency === FeeFrequency::Daily;
        $quantity = $period->quantity;
        $base = $daily ? $quantity * $tariff->amount : $tariff->amount;

        if ($midPeriod === MidPeriod::Prorated) {
            if ($daily) {
                $quantity = $this->periods->quantityBetween($season, $enrollment->group, $enrolledOn, $period->end);
                $base = $quantity * $tariff->amount;
            } else {
                $remaining = (int) $enrolledOn->diffInDays($period->end) + 1;
                $base = (int) round($tariff->amount * $remaining / $period->days());
            }
        }

        if ($base <= 0) {
            return null;
        }

        $shown = new BillingPeriod($period->start, $period->end, $period->dueOn, $quantity);
        $wholeMonth = $daily && ($season->daily_grouping ?? DailyGrouping::Month) === DailyGrouping::Month;

        // Quien se inscribe con el período empezado tiene los mismos días para pagar desde que se inscribe.
        $dueOn = $period->contains($enrolledOn) ? $period->dueOn->max($enrolledOn->addDays($season->due_days)) : $period->dueOn;

        return [
            'due_on' => $dueOn->toDateString(),
            'tariff_id' => $tariff->id,
            'base_amount' => $base,
            'quantity' => $daily ? $quantity : null,
            'unit_amount' => $daily ? $tariff->amount : null,
            'description' => $shown->description($season->fee_frequency, $daily ? ($season->daily_basis ?? DailyBasis::Training) : null, $wholeMonth)
                .($midPeriod === MidPeriod::Prorated && ! $daily ? ' (proporcional)' : ''),
        ];
    }
}
