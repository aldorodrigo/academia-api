<?php

namespace App\Actions\Billing;

use App\Enums\DailyBasis;
use App\Enums\DailyGrouping;
use App\Enums\FeeFrequency;
use App\Models\Group;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Períodos de cobro de una temporada según su plan:
 *  - mensual: meses calendario;
 *  - quincenal: del 1 al 15 y del 16 a fin de mes;
 *  - semanal: de lunes a domingo;
 *  - por día: agrupados por día, semana o mes, con la cantidad de días de entrenamiento
 *    según el horario de la categoría (sin días de entrenamiento no hay cuota).
 *
 * Cada período se recorta a las fechas de la temporada. Vence `due_days` después de empezar
 * (nunca antes del inicio recortado).
 */
class SeasonPeriods
{
    /**
     * @return Collection<int, BillingPeriod>
     */
    public function for(Season $season, ?Group $group = null, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Collection
    {
        $frequency = $season->fee_frequency;

        if ($frequency === null) {
            return collect();
        }

        $seasonStart = $season->starts_on->startOfDay();
        $seasonEnd = $season->ends_on->startOfDay();
        $from = ($from ?? $seasonStart)->startOfDay()->max($seasonStart);
        $to = ($to ?? $seasonEnd)->startOfDay()->min($seasonEnd);
        $weekdays = $frequency === FeeFrequency::Daily ? $this->trainingWeekdays($group) : null;

        $periods = collect();
        $cursor = $this->unitStart($season, $from);

        while ($cursor->lte($to)) {
            $nominalEnd = $this->unitEnd($season, $cursor);
            $start = $cursor->max($seasonStart);
            $end = $nominalEnd->min($seasonEnd);

            $quantity = $weekdays === null ? null : $this->countDays($start, $end, $weekdays);

            if ($quantity !== 0) {
                $periods->push(new BillingPeriod(
                    start: $start,
                    end: $end,
                    dueOn: $cursor->addDays($season->due_days)->max($start),
                    quantity: $quantity,
                ));
            }

            $cursor = $nominalEnd->addDay();
        }

        // Solo los que tocan el rango pedido.
        return $periods->filter(fn (BillingPeriod $period) => $period->end->gte($from) && $period->start->lte($to))->values();
    }

    /**
     * Período que contiene una fecha (null si esa fecha no tiene cuota).
     */
    public function containing(Season $season, ?Group $group, CarbonImmutable $date): ?BillingPeriod
    {
        return $this->for($season, $group, $date, $date)->first(fn (BillingPeriod $period) => $period->contains($date));
    }

    /**
     * Días a cobrar dentro de un tramo (para el proporcional del cobro por día).
     */
    public function quantityBetween(Season $season, ?Group $group, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return $this->countDays($start, $end, $this->trainingWeekdays($group));
    }

    private function unitStart(Season $season, CarbonImmutable $date): CarbonImmutable
    {
        return match ($this->unit($season)) {
            'month' => $date->startOfMonth(),
            'fortnight' => $date->day <= 15 ? $date->startOfMonth() : $date->startOfMonth()->setDay(16),
            'week' => $date->startOfWeek(CarbonImmutable::MONDAY),
            'day' => $date->startOfDay(),
        };
    }

    private function unitEnd(Season $season, CarbonImmutable $start): CarbonImmutable
    {
        return match ($this->unit($season)) {
            'month' => $start->endOfMonth()->startOfDay(),
            'fortnight' => $start->day <= 15 ? $start->setDay(15) : $start->endOfMonth()->startOfDay(),
            'week' => $start->addDays(6),
            'day' => $start,
        };
    }

    private function unit(Season $season): string
    {
        return match ($season->fee_frequency) {
            FeeFrequency::Monthly => 'month',
            FeeFrequency::Fortnightly => 'fortnight',
            FeeFrequency::Weekly => 'week',
            FeeFrequency::Daily => match ($season->daily_grouping ?? DailyGrouping::Month) {
                DailyGrouping::Day => 'day',
                DailyGrouping::Week => 'week',
                DailyGrouping::Month => 'month',
            },
        };
    }

    /**
     * Días ISO (1 = lunes) con horario en la categoría. Sin categoría (vista previa), lunes a viernes.
     *
     * @return list<int>
     */
    private function trainingWeekdays(?Group $group): array
    {
        if ($group === null) {
            return [1, 2, 3, 4, 5];
        }

        return $group->schedules()->withoutGlobalScopes()->pluck('weekday')
            ->map(fn ($weekday) => (int) $weekday)->unique()->values()->all();
    }

    /**
     * @param  list<int>  $weekdays
     */
    private function countDays(CarbonImmutable $start, CarbonImmutable $end, array $weekdays): int
    {
        $count = 0;

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $count += in_array($day->dayOfWeekIso, $weekdays, true) ? 1 : 0;
        }

        return $count;
    }

    /**
     * La cantidad sale de la asistencia (todavía no hay módulo): no se puede calcular.
     */
    public static function needsAttendance(Season $season): bool
    {
        return $season->fee_frequency === FeeFrequency::Daily && $season->daily_basis === DailyBasis::Attendance;
    }
}
