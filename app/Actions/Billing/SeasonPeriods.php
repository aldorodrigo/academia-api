<?php

namespace App\Actions\Billing;

use App\Enums\ClassStatus;
use App\Enums\DailyBasis;
use App\Enums\DailyGrouping;
use App\Enums\FeeFrequency;
use App\Models\ClassSession;
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
        $waived = $weekdays === null ? [] : $this->waivedDates($season, $group, $from, $to);

        $periods = collect();
        $cursor = $this->unitStart($season, $from);

        while ($cursor->lte($to)) {
            $nominalEnd = $this->unitEnd($season, $cursor);
            $start = $cursor->max($seasonStart);
            $end = $nominalEnd->min($seasonEnd);

            $quantity = $weekdays === null ? null : $this->countDays($start, $end, $weekdays, $waived);

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
        return $this->countDays($start, $end, $this->trainingWeekdays($group), $this->waivedDates($season, $group, $start, $end));
    }

    /**
     * Clases que se dieron en un tramo (cobro por clase dictada): los días con horario sin las
     * clases suspendidas o reprogramadas, más las recuperaciones.
     */
    public function taughtBetween(Group $group, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $sessions = ClassSession::query()->withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get();

        $off = $sessions->filter(fn (ClassSession $session) => ! $session->is_makeup && $session->isOff())
            ->map(fn (ClassSession $session) => $session->date->toDateString())
            ->unique()->values()->all();
        $makeups = $sessions->filter(fn (ClassSession $session) => $session->is_makeup && ! $session->isOff())->count();

        return $this->countDays($start, $end, $this->trainingWeekdays($group), $off) + $makeups;
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
     * Días con clase suspendida que no se cobra (solo por día de entrenamiento).
     *
     * @return list<string> fechas AAAA-MM-DD
     */
    private function waivedDates(Season $season, ?Group $group, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($group === null || ($season->daily_basis ?? DailyBasis::Training) !== DailyBasis::Training) {
            return [];
        }

        // El rango se amplía al período completo que contiene cada punta.
        return ClassSession::query()->withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('status', ClassStatus::Suspended)
            ->where('charge_waived', true)
            ->whereDate('date', '>=', $this->unitStart($season, $from)->toDateString())
            ->whereDate('date', '<=', $this->unitEnd($season, $this->unitStart($season, $to))->toDateString())
            ->pluck('date')
            ->map(fn ($date) => CarbonImmutable::parse($date)->toDateString())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $weekdays
     * @param  list<string>  $waived  fechas que no se cuentan
     */
    private function countDays(CarbonImmutable $start, CarbonImmutable $end, array $weekdays, array $waived = []): int
    {
        $count = 0;

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $count += in_array($day->dayOfWeekIso, $weekdays, true) && ! in_array($day->toDateString(), $waived, true) ? 1 : 0;
        }

        return $count;
    }

    /**
     * La cantidad sale de la asistencia o de las clases dictadas: no se puede calcular por adelantado.
     */
    public static function needsAttendance(Season $season): bool
    {
        return $season->chargesAfterPeriod();
    }
}
