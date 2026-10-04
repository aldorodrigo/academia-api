<?php

namespace App\Filament\Support;

use App\Actions\Billing\BillingPeriod;
use App\Actions\Billing\SeasonPeriods;
use App\Enums\DailyBasis;
use App\Enums\FeeFrequency;
use App\Enums\MidPeriod;
use App\Models\FeeConcept;
use App\Models\Group;
use App\Models\Season;
use App\Models\Tariff;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Inscripción a mitad de mes (quincena, semana): si corresponde preguntar qué se cobra de la
 * cuota en curso y el efecto de cada opción ("Se cobrará ₲ 60.000 (12 de 30 días)").
 */
class MidPeriodPreview
{
    public static function applies(Get $get): bool
    {
        return self::context($get) !== null;
    }

    public static function text(Get $get): ?string
    {
        $context = self::context($get);

        if ($context === null) {
            return null;
        }

        [$season, $group, $period, $enrolledOn] = $context;
        $tariff = Tariff::applicable(FeeConcept::monthlyFee(filament()->getTenant()), $season, $group, $period->start);
        $daily = $season->fee_frequency === FeeFrequency::Daily;
        $format = fn (int $amount) => Money::pyg($amount)->format();
        $next = $period->end->addDay()->format('d/m');

        if ($tariff === null) {
            return 'Todavía no hay monto cargado para esta categoría en la temporada.';
        }

        $mode = $get('mid_period');
        $unit = $season->billingUnit();

        return match (($mode instanceof MidPeriod ? $mode : MidPeriod::tryFrom((string) $mode)) ?? $season->mid_period) {
            MidPeriod::Next => 'No se cobra '.$unit->current().": se empieza a cobrar desde el {$next}.",
            MidPeriod::Full => 'Se cobrará '.$unit->whole().': '.$format($daily ? $period->quantity * $tariff->amount : $tariff->amount).'.',
            MidPeriod::Prorated => $daily
                ? (function () use ($season, $group, $period, $enrolledOn, $tariff, $format) {
                    $days = app(SeasonPeriods::class)->quantityBetween($season, $group, $enrolledOn, $period->end);
                    // En entrenamientos o clases, no en días corridos: "12 de 20 entrenamientos".
                    $total = ($season->daily_basis ?? DailyBasis::Training)->quantityLabel($period->quantity);

                    return 'Se cobrará '.$format($days * $tariff->amount)." ({$days} de {$total}).";
                })()
                : (function () use ($period, $enrolledOn, $tariff, $format) {
                    $days = (int) $enrolledOn->diffInDays($period->end) + 1;

                    return 'Se cobrará '.$format((int) round($tariff->amount * $days / $period->days()))." ({$days} de {$period->days()} días).";
                })(),
        };
    }

    /**
     * @return array{Season, Group, BillingPeriod, CarbonImmutable}|null
     */
    private static function context(Get $get): ?array
    {
        $season = filled($get('season_id')) ? Season::query()->find($get('season_id')) : null;
        $group = filled($get('group_id')) ? Group::query()->find($get('group_id')) : null;

        if ($season === null || $group === null || ! $season->hasFeePlan() || $season->chargesAfterPeriod()
            || ! $season->billingUnit()->allowsMidway()) {
            return null;
        }

        $enrolledOn = filled($get('enrolled_on'))
            ? CarbonImmutable::parse($get('enrolled_on'))->startOfDay()
            : filament()->getTenant()->today();
        $period = app(SeasonPeriods::class)->containing($season, $group, $enrolledOn);

        return $period !== null && $enrolledOn->gt($period->start) ? [$season, $group, $period, $enrolledOn] : null;
    }
}
