<?php

namespace App\Filament\Resources\Seasons\Pages;

use App\Filament\Resources\Seasons\SeasonResource;
use App\Filament\Resources\Seasons\Support\SeasonActions;
use App\Filament\Resources\Seasons\Support\SeasonPlanSteps;
use App\Models\Season;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSeason extends EditRecord
{
    protected static string $resource = SeasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SeasonActions::configurePlan(),
            SeasonActions::changeAmount(),
            // Con inscripciones o cuotas no se borra.
            DeleteAction::make()->hidden(fn (Season $record) => $record->enrollments()->exists() || $record->charges()->exists()),
        ];
    }

    /**
     * Sin permiso de cobro, el plan no se toca; "Todas juntas" solo con permiso de cuotas.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! SeasonPlanSteps::canPlan() || ! $this->getRecord()->hasFeePlan()) {
            return collect($data)->only(['name', 'kind', 'starts_on', 'ends_on'])->all();
        }

        $data['issue_upfront'] = SeasonPlanSteps::canIssueUpfront()
            ? filter_var($data['issue_upfront'] ?? false, FILTER_VALIDATE_BOOLEAN)
            : $this->getRecord()->issue_upfront;

        return collect($data)->except(['fee_amount', 'enrollment_fee_amount', 'increase_percent', 'has_group_amounts', 'group_amounts', 'base_fee_amount', 'base_enrollment_fee_amount'])->all();
    }
}
