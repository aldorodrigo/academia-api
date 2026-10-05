<?php

namespace App\Http\Controllers\Api\V1\Setup;

use App\Actions\Seasons\CreateSeason;
use App\Enums\DailyBasis;
use App\Enums\DailyGrouping;
use App\Enums\FeeFrequency;
use App\Enums\MidPeriod;
use App\Enums\SeasonKind;
use App\Enums\SeasonStatus;
use App\Filament\Resources\Seasons\Support\SeasonPlan;
use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Season;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Paso 3 de la guía: temporada y cuotas, con los mismos cálculos que el asistente del panel.
 */
class SeasonController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Season::query()->orderByDesc('starts_on')->get()->map(fn (Season $season) => self::item($season))->values(),
        ]);
    }

    /**
     * Estado inicial sugerido del asistente.
     */
    public function create(CurrentOrganization $current): JsonResponse
    {
        $defaults = SeasonPlan::defaults($current->get());

        return response()->json(['data' => [
            // En la guía, la primera temporada se sugiere para todas las disciplinas.
            'program_ids' => Program::query()->orderBy('name')->pluck('id')->all(),
            'kind' => $defaults['kind'],
            'starts_on' => $defaults['starts_on'],
            'ends_on' => $defaults['ends_on'],
            'name' => $defaults['name'],
            'fee_frequency' => $defaults['fee_frequency'],
            'daily_basis' => $defaults['daily_basis'],
            'daily_grouping' => $defaults['daily_grouping'],
            'fee_amount' => null,
            'enrollment_fee_amount' => null,
            'group_amounts' => [],
            'due_days' => $defaults['due_days'],
            'issue_upfront' => false,
            'mid_period' => $defaults['mid_period'],
        ]]);
    }

    /**
     * Fechas y plan sugeridos, resumen en palabras y primeras cuotas (el estado puede estar incompleto).
     */
    public function preview(Request $request, CurrentOrganization $current): JsonResponse
    {
        $organization = $current->get();
        $state = self::state($request->all());
        $kind = SeasonKind::tryFrom((string) ($state['kind'] ?? '')) ?? SeasonKind::Annual;
        $startsOn = filled($state['starts_on'] ?? null)
            ? CarbonImmutable::parse($state['starts_on'])
            : CarbonImmutable::parse(SeasonPlan::defaults($organization)['starts_on']);
        $plan = SeasonPlan::planFor($kind, $organization);
        $dueDay = (int) $organization->billing('due_day');

        return response()->json(['data' => [
            'dates' => collect(SeasonPlan::dates($kind, $startsOn))->only(['ends_on', 'name']),
            'plan' => [
                'fee_frequency' => $plan['fee_frequency'],
                'due_days' => $plan['due_days'],
                'due_days_by_frequency' => collect(FeeFrequency::cases())
                    ->mapWithKeys(fn (FeeFrequency $frequency) => [$frequency->value => $frequency->defaultDueDays($dueDay)]),
            ],
            'kinds' => collect(SeasonKind::cases())->map(fn (SeasonKind $item) => [
                'value' => $item->value,
                'label' => $item->getLabel(),
                'example' => $item->example($startsOn),
            ])->values(),
            'summary' => SeasonPlan::summary($state),
            'examples' => SeasonPlan::examples($state)->values(),
            'due_example' => SeasonPlan::dueExample($state),
            'periods_count' => SeasonPlan::periodsCount($state),
            'terms' => self::terms($state),
        ]]);
    }

    /**
     * Las palabras del plan elegido (mes, quincena, semana o día), ya armadas: la app no calcula
     * género ni artículos. Null sin plan de cobro.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    private static function terms(array $state): ?array
    {
        $unit = SeasonPlan::unit($state);

        if ($unit === null) {
            return null;
        }

        $dueDays = (int) ($state['due_days'] ?? 0);
        $count = SeasonPlan::periodsCount($state);

        return [
            'unit' => $unit->noun(),
            'issue_now' => ucfirst($unit->createdAtStart()),
            'issue_now_help' => 'La familia ve solo la cuota '.$unit->ofCurrent().'.',
            'issue_upfront_help' => 'La familia ve '.SeasonPlan::allPeriods($count).': la '.$unit->ofCurrent().' para pagar y el resto como próximas.',
            'issue_after' => ucfirst($unit->createdAfter()),
            'basis_after' => 'La cuota se crea '.$unit->createdAfter().'.',
            'midway' => $unit->allowsMidway() ? 'Si alguien se inscribe '.$unit->midway().', se cobra' : null,
            'mid_period_options' => collect(MidPeriod::optionsFor($unit))
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'due_question' => $unit->dueQuestion(),
            'due_options' => collect($unit->dueOptions($dueDays))
                ->map(fn (string $label, int $value) => ['value' => $value, 'label' => $label])->values(),
            'due_text' => $unit->dueText($dueDays),
        ];
    }

    public function store(Request $request, CreateSeason $create): JsonResponse
    {
        $multiple = Program::query()->count() > 1;
        $daily = $request->input('fee_frequency') === FeeFrequency::Daily->value;
        $plan = filled($request->input('fee_frequency'));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(SeasonKind::class)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'program_ids' => [$multiple ? 'required' : 'nullable', 'array'],
            'program_ids.*' => ['integer', Rule::exists('programs', 'id')->where('organization_id', app(CurrentOrganization::class)->id())],
            'fee_frequency' => ['nullable', Rule::enum(FeeFrequency::class)],
            'daily_basis' => [$daily ? 'required' : 'nullable', Rule::enum(DailyBasis::class)],
            'daily_grouping' => [$daily ? 'required' : 'nullable', Rule::enum(DailyGrouping::class)],
            'fee_amount' => [$plan ? 'required' : 'nullable', 'integer', 'min:1'],
            'enrollment_fee_amount' => ['nullable', 'integer', 'min:1'],
            'group_amounts' => ['nullable', 'array'],
            'group_amounts.*.group_id' => ['required', 'integer', Rule::exists('groups', 'id')->where('organization_id', app(CurrentOrganization::class)->id())],
            'group_amounts.*.amount' => ['required', 'integer', 'min:1'],
            'due_days' => [$plan ? 'required' : 'nullable', 'integer', 'min:0', 'max:60'],
            'issue_upfront' => ['nullable', 'boolean'],
            'mid_period' => [$plan ? 'required' : 'nullable', Rule::enum(MidPeriod::class)],
        ], [
            'ends_on.after_or_equal' => 'Tiene que terminar después de empezar.',
            'program_ids.required' => 'Elegí al menos una disciplina.',
            'fee_amount.required' => 'Ingresá el monto de la cuota.',
            'fee_amount.min' => 'El monto tiene que ser mayor a 0.',
            'due_days.max' => 'El vencimiento tiene que ser de 0 a 60 días.',
        ]);

        $season = $create->handle(self::state($data));

        return response()->json(['data' => self::item($season)], Response::HTTP_CREATED);
    }

    /**
     * Estado de la API → estado del asistente (montos por categoría como en el panel).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function state(array $input): array
    {
        $groupAmounts = collect($input['group_amounts'] ?? [])
            ->filter(fn ($row) => is_array($row) && filled($row['group_id'] ?? null))
            ->values()->all();

        return [
            ...collect($input)->only([
                'program_ids', 'kind', 'starts_on', 'ends_on', 'name', 'fee_frequency', 'daily_basis',
                'daily_grouping', 'fee_amount', 'enrollment_fee_amount', 'due_days', 'issue_upfront', 'mid_period',
            ])->all(),
            'program_ids' => array_values((array) ($input['program_ids'] ?? [])),
            'has_group_amounts' => $groupAmounts !== [],
            'group_amounts' => $groupAmounts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(Season $season): array
    {
        return [
            'id' => $season->id,
            'name' => $season->name,
            'starts_on' => $season->starts_on->toDateString(),
            'ends_on' => $season->ends_on->toDateString(),
            'status' => match ($season->status()) {
                SeasonStatus::Active => 'vigente',
                SeasonStatus::Upcoming => 'proxima',
                SeasonStatus::Finished => 'terminada',
            },
            'has_fee_plan' => $season->hasFeePlan(),
        ];
    }
}
