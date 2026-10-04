<?php

namespace App\Actions\Seasons;

use App\Enums\FeeFrequency;
use App\Enums\MidPeriod;
use App\Filament\Resources\Seasons\Support\SeasonPlan;
use App\Models\Program;
use App\Models\Season;
use Illuminate\Support\Facades\DB;

/**
 * Crea una temporada con su plan de cobro y sus montos (asistente del panel y guía de la app).
 */
class CreateSeason
{
    /**
     * @param  array<string, mixed>  $data  estado del asistente
     * @param  bool  $withPlan  quien crea puede cargar tarifas
     * @param  bool  $canIssueUpfront  quien crea puede crear cuotas por adelantado
     */
    public function handle(array $data, bool $withPlan = true, bool $canIssueUpfront = true): Season
    {
        $withPlan = $withPlan && filled($data['fee_frequency'] ?? null);
        $daily = ($data['fee_frequency'] ?? null) === FeeFrequency::Daily->value;

        return DB::transaction(function () use ($data, $withPlan, $daily, $canIssueUpfront) {
            $season = Season::query()->create([
                'name' => $data['name'],
                'kind' => $data['kind'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                ...($withPlan ? [
                    'fee_frequency' => $data['fee_frequency'],
                    'daily_basis' => $daily ? ($data['daily_basis'] ?? null) : null,
                    'daily_grouping' => $daily ? ($data['daily_grouping'] ?? null) : null,
                    'due_days' => (int) $data['due_days'],
                    'issue_upfront' => $canIssueUpfront && filter_var($data['issue_upfront'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'mid_period' => $data['mid_period'] ?? MidPeriod::Full->value,
                ] : []),
            ]);

            // Con una sola disciplina, se asigna sola.
            $programs = $data['program_ids'] ?? [];
            $season->programs()->sync($programs !== [] ? $programs : Program::query()->pluck('id')->all());

            if ($withPlan) {
                SeasonPlan::saveTariffs($season, $data);
            }

            return $season;
        });
    }
}
