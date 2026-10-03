<?php

namespace App\Filament\Resources\Seasons\Support;

use App\Actions\Billing\BillingPeriod;
use App\Actions\Billing\SeasonPeriods;
use App\Enums\DailyBasis;
use App\Enums\DailyGrouping;
use App\Enums\FeeFrequency;
use App\Enums\MidPeriod;
use App\Enums\SeasonKind;
use App\Models\FeeConcept;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Season;
use App\Models\Tariff;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Plan de cobro de una temporada a partir del estado del asistente: valores por defecto,
 * copia de otra temporada, resumen en palabras, cuotas de ejemplo y guardado de los montos.
 */
class SeasonPlan
{
    /**
     * Estado inicial del asistente: temporada anual desde el próximo 1 de enero (o desde
     * el mes que viene en el primer semestre), cuota mensual al empezar cada mes.
     *
     * @return array<string, mixed>
     */
    public static function defaults(Organization $organization): array
    {
        $today = $organization->today();
        $startsOn = $today->month >= 7 ? $today->addYear()->startOfYear() : $today->startOfMonth()->addMonth();
        $programs = Program::query()->pluck('id');

        return [
            'copy_from' => '',
            'program_ids' => $programs->count() === 1 ? $programs->all() : [],
            ...self::dates(SeasonKind::Annual, $startsOn),
            ...self::planFor(SeasonKind::Annual, $organization),
            'fee_amount' => null,
            'enrollment_fee_amount' => null,
            'increase_percent' => null,
            'has_group_amounts' => false,
            'group_amounts' => [],
        ];
    }

    /**
     * Fin y nombre sugerido según la duración.
     *
     * @return array{kind: string, starts_on: string, ends_on: string, name: string}
     */
    public static function dates(SeasonKind $kind, CarbonImmutable $startsOn): array
    {
        return [
            'kind' => $kind->value,
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $kind->endsOn($startsOn)->toDateString(),
            'name' => $kind->suggestedName($startsOn),
        ];
    }

    /**
     * Plan de cobro sugerido para la duración.
     *
     * @return array<string, mixed>
     */
    public static function planFor(SeasonKind $kind, Organization $organization): array
    {
        $frequency = $kind->defaultFrequency();

        return [
            'fee_frequency' => $frequency->value,
            'daily_basis' => DailyBasis::Training->value,
            'daily_grouping' => DailyGrouping::Month->value,
            'due_days' => $frequency->defaultDueDays((int) $organization->billing('due_day')),
            'issue_upfront' => '0',
            'mid_period' => MidPeriod::Full->value,
        ];
    }

    /**
     * Copia de otra temporada: disciplinas, duración (la siguiente), plan y montos vigentes.
     *
     * @return array<string, mixed>
     */
    public static function copyOf(Season $source): array
    {
        $startsOn = $source->ends_on->addDay();
        $kind = $source->kind ?? SeasonKind::Annual;
        $concept = FeeConcept::monthlyFee($source->organization);
        $enrollment = FeeConcept::enrollmentFee($source->organization);
        $latest = fn (?FeeConcept $concept, ?int $groupId = null) => $concept === null ? null : $source->tariffs()
            ->where('fee_concept_id', $concept->id)
            ->when($groupId, fn ($query) => $query->where('group_id', $groupId), fn ($query) => $query->whereNull('group_id'))
            ->orderByDesc('valid_from')->orderByDesc('id')
            ->value('amount');
        $groupAmounts = $concept === null ? [] : $source->tariffs()
            ->where('fee_concept_id', $concept->id)->whereNotNull('group_id')
            ->pluck('group_id')->unique()
            ->map(fn (int $groupId) => ['group_id' => $groupId, 'amount' => $latest($concept, $groupId), 'base_amount' => $latest($concept, $groupId)])
            ->values()->all();

        return [
            'copy_from' => (string) $source->id,
            'program_ids' => $source->programs()->pluck('programs.id')->all(),
            ...self::dates($kind, $startsOn),
            'fee_frequency' => $source->fee_frequency?->value ?? $kind->defaultFrequency()->value,
            'daily_basis' => $source->daily_basis?->value ?? DailyBasis::Training->value,
            'daily_grouping' => $source->daily_grouping?->value ?? DailyGrouping::Month->value,
            'due_days' => $source->due_days,
            'issue_upfront' => $source->issue_upfront ? '1' : '0',
            'mid_period' => $source->mid_period?->value ?? MidPeriod::Full->value,
            'fee_amount' => $latest($concept),
            'base_fee_amount' => $latest($concept),
            'enrollment_fee_amount' => $latest($enrollment),
            'base_enrollment_fee_amount' => $latest($enrollment),
            'increase_percent' => null,
            'has_group_amounts' => $groupAmounts !== [],
            'group_amounts' => $groupAmounts,
        ];
    }

    /**
     * Aplica un aumento sobre los montos copiados, redondeado a miles.
     */
    public static function increase(?int $base, mixed $percent): ?int
    {
        if ($base === null) {
            return null;
        }

        return (int) (round($base * (1 + ((float) $percent) / 100) / 1000) * 1000);
    }

    /**
     * Temporada sin guardar, para calcular períodos de ejemplo.
     *
     * @param  array<string, mixed>  $state
     */
    public static function draft(array $state): ?Season
    {
        if (blank($state['starts_on'] ?? null) || blank($state['ends_on'] ?? null)) {
            return null;
        }

        return new Season([
            'name' => $state['name'] ?? '',
            'kind' => $state['kind'] ?? SeasonKind::Annual->value,
            'starts_on' => $state['starts_on'],
            'ends_on' => $state['ends_on'],
            'fee_frequency' => filled($state['fee_frequency'] ?? null) ? $state['fee_frequency'] : null,
            'daily_basis' => $state['daily_basis'] ?? null,
            'daily_grouping' => $state['daily_grouping'] ?? null,
            'due_days' => (int) ($state['due_days'] ?? 0),
            'issue_upfront' => filter_var($state['issue_upfront'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'mid_period' => $state['mid_period'] ?? MidPeriod::Full->value,
        ]);
    }

    /**
     * Primeras cuotas de ejemplo (sin categoría: en el cobro por día, lunes a viernes).
     *
     * @param  array<string, mixed>  $state
     * @return Collection<int, array{period: string, due_on: string, amount: string}>
     */
    public static function examples(array $state, int $count = 3): Collection
    {
        $season = self::draft($state);

        if ($season === null || ! $season->hasFeePlan()) {
            return collect();
        }

        $amount = (int) ($state['fee_amount'] ?? 0);
        $daily = $season->fee_frequency === FeeFrequency::Daily;

        return app(SeasonPeriods::class)->for($season)->take($count)->map(fn (BillingPeriod $period) => [
            'period' => self::periodLabel($period, $season),
            'due_on' => $period->dueOn->format('d/m/Y'),
            'amount' => Money::pyg($daily ? $period->quantity * $amount : $amount)->format()
                .($daily ? ' ('.($period->quantity === 1 ? '1 día' : "{$period->quantity} días").' × '.Money::pyg($amount)->format().')' : ''),
        ]);
    }

    /**
     * Cantidad de cuotas por jugador en toda la temporada.
     *
     * @param  array<string, mixed>  $state
     */
    public static function periodsCount(array $state): int
    {
        $season = self::draft($state);

        return $season === null ? 0 : app(SeasonPeriods::class)->for($season)->count();
    }

    /**
     * "La cuota de enero 2027 vence el 10/01/2027."
     *
     * @param  array<string, mixed>  $state
     */
    public static function dueExample(array $state): ?string
    {
        $season = self::draft($state);
        $first = $season?->hasFeePlan() ? app(SeasonPeriods::class)->for($season)->first() : null;

        return $first === null ? null
            : 'Por ejemplo, la cuota de '.self::periodLabel($first, $season).' vence el '.$first->dueOn->format('d/m/Y').'.';
    }

    /**
     * Resumen en palabras del asistente.
     *
     * @param  array<string, mixed>  $state
     */
    public static function summary(array $state, bool $withPlan = true): string
    {
        $season = self::draft($state);

        if ($season === null) {
            return 'Completá las fechas de la temporada.';
        }

        $programs = Program::query()->whereKey($state['program_ids'] ?? [])->orderBy('name')->pluck('name');
        $text = sprintf(
            '%s%s, del %s al %s.',
            $state['name'] ?? 'Temporada',
            $programs->isEmpty() ? '' : ' de '.$programs->join(', ', ' y '),
            $season->starts_on->format('d/m/Y'),
            $season->ends_on->format('d/m/Y'),
        );

        if (! $withPlan || ! $season->hasFeePlan()) {
            return $text.' Sin plan de cobro: se configura después con "Configurar cobro".';
        }

        $frequency = $season->fee_frequency;
        $amount = Money::pyg((int) ($state['fee_amount'] ?? 0))->format();
        $groups = collect($state['has_group_amounts'] ?? false ? ($state['group_amounts'] ?? []) : [])
            ->filter(fn (array $row) => filled($row['group_id'] ?? null) && filled($row['amount'] ?? null))
            ->map(fn (array $row) => Group::query()->whereKey($row['group_id'])->value('name').': '.Money::pyg((int) $row['amount'])->format());

        $text .= match ($frequency) {
            FeeFrequency::Daily => " Se cobra {$amount} por ".(match ($season->daily_basis) {
                DailyBasis::Attendance => 'clase asistida',
                DailyBasis::Taught => 'clase dictada',
                default => 'día de entrenamiento',
            })
                .', '.mb_strtolower($season->daily_grouping?->getLabel() ?? 'una cuota por mes'),
            default => ' Cuota '.mb_strtolower($frequency->getLabel())." de {$amount}",
        };
        $text .= $groups->isEmpty() ? '' : ' ('.$groups->join(', ').')';
        $text .= ', que vence '.($season->due_days === 0 ? 'el día que empieza' : "{$season->due_days} días después de empezar").' cada período.';

        if (filled($state['enrollment_fee_amount'] ?? null)) {
            $text .= ' Inscripción '.Money::pyg((int) $state['enrollment_fee_amount'])->format().'.';
        }

        $text .= match (true) {
            $season->chargesByAttendance() => ' Cada cuota se crea cuando termina su período, con las clases a las que vino según la asistencia.',
            $season->chargesAfterPeriod() => ' Cada cuota se crea cuando termina su período, con las clases que se dieron (las suspendidas no se cobran).',
            $season->issue_upfront => ' Las '.self::periodsCount($state).' cuotas de cada jugador se crean todas al inscribirlo.',
            default => ' Cada cuota se crea al empezar su período.',
        };

        return $text;
    }

    /**
     * Guarda los montos como tarifas de la temporada (desde su inicio).
     *
     * @param  array<string, mixed>  $state
     */
    public static function saveTariffs(Season $season, array $state): void
    {
        $organization = $season->organization;
        $validFrom = $season->starts_on->toDateString();
        $create = fn (?FeeConcept $concept, mixed $amount, ?int $groupId = null) => $concept !== null && filled($amount) && (int) $amount > 0
            ? Tariff::query()->create([
                'fee_concept_id' => $concept->id,
                'season_id' => $season->id,
                'group_id' => $groupId,
                'amount' => (int) $amount,
                'valid_from' => $validFrom,
                'created_by' => auth()->id(),
            ])
            : null;

        $create(FeeConcept::monthlyFee($organization), $state['fee_amount'] ?? null);
        $create(FeeConcept::enrollmentFee($organization), $state['enrollment_fee_amount'] ?? null);

        if ($state['has_group_amounts'] ?? false) {
            foreach ($state['group_amounts'] ?? [] as $row) {
                if (filled($row['group_id'] ?? null)) {
                    $create(FeeConcept::monthlyFee($organization), $row['amount'] ?? null, (int) $row['group_id']);
                }
            }
        }
    }

    private static function periodLabel(BillingPeriod $period, Season $season): string
    {
        return match ($season->fee_frequency) {
            FeeFrequency::Monthly => $period->start->locale('es')->translatedFormat('F Y'),
            default => $period->start->format('d/m').($period->days() > 1 ? ' al '.$period->end->format('d/m') : ''),
        };
    }
}
