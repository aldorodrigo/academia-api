<?php

namespace App\Filament\Resources\Seasons\Pages;

use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Pages\SeasonTransfer;
use App\Filament\Resources\Seasons\SeasonResource;
use App\Filament\Resources\Seasons\Support\SeasonPlan;
use App\Filament\Resources\Seasons\Support\SeasonPlanSteps;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\Season;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\HasWizard;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Asistente de temporada: 1) temporada, 2) cuotas, 3) cuándo se crean, 4) revisar.
 * Los pasos de cobro solo los ve quien puede cargar tarifas; sin ellos la temporada
 * queda "Sin plan de cobro". Al terminar ofrece pasar a los jugadores de la anterior.
 */
class CreateSeason extends CreateRecord
{
    use HasWizard;

    protected static string $resource = SeasonResource::class;

    protected static ?string $title = 'Nueva temporada';

    public function mount(): void
    {
        parent::mount();

        $this->form->fill(SeasonPlan::defaults(Filament::getTenant()));
    }

    public function getSteps(): array
    {
        return SeasonPlanSteps::wizard();
    }

    protected function handleRecordCreation(array $data): Model
    {
        $withPlan = SeasonPlanSteps::canPlan();

        return DB::transaction(function () use ($data, $withPlan) {
            $season = Season::query()->create([
                'name' => $data['name'],
                'kind' => $data['kind'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                ...($withPlan ? [
                    'fee_frequency' => $data['fee_frequency'],
                    'daily_basis' => $data['fee_frequency'] === 'diaria' ? ($data['daily_basis'] ?? null) : null,
                    'daily_grouping' => $data['fee_frequency'] === 'diaria' ? ($data['daily_grouping'] ?? null) : null,
                    'due_days' => (int) $data['due_days'],
                    'issue_upfront' => SeasonPlanSteps::canIssueUpfront() && filter_var($data['issue_upfront'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'mid_period' => $data['mid_period'],
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

    /**
     * Siguiente paso: pasar a los jugadores de la temporada anterior de esas disciplinas.
     */
    protected function getCreatedNotification(): ?Notification
    {
        /** @var Season $season */
        $season = $this->getRecord();
        $previous = SeasonTransfer::previousOf($season);
        $players = $previous === null ? 0 : Enrollment::query()->where('season_id', $previous->id)->distinct()->count('student_id');

        $notification = Notification::make()->success()->title("Temporada {$season->name} creada.");

        if ($players === 0) {
            return $notification;
        }

        return $notification
            ->body("¿Pasamos a los {$players} jugadores de {$previous->name} ahora?")
            ->persistent()
            ->actions([
                Action::make('transfer')
                    ->label('Pasar jugadores')
                    ->button()
                    ->url(EnrollmentResource::getUrl('transfer', ['from' => $previous->id, 'to' => $season->id])),
                Action::make('later')->label('Más tarde')->close(),
            ]);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
