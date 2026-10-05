<?php

namespace App\Filament\Resources\Seasons\Pages;

use App\Actions\Seasons\CreateSeason as CreateSeasonAction;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Pages\SeasonTransfer;
use App\Filament\Resources\Seasons\SeasonResource;
use App\Filament\Resources\Seasons\Support\SeasonPlan;
use App\Filament\Resources\Seasons\Support\SeasonPlanSteps;
use App\Filament\Support\Terms;
use App\Models\Enrollment;
use App\Models\Season;
use App\Support\Vocabulary;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\HasWizard;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

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

    /** Se abrió desde "Primeros pasos": al terminar vuelve ahí. */
    public bool $fromGuide = false;

    public function mount(): void
    {
        parent::mount();

        $this->fromGuide = request()->boolean('guia');

        $this->form->fill(SeasonPlan::defaults(Filament::getTenant()));
    }

    public function getSteps(): array
    {
        return SeasonPlanSteps::wizard();
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateSeasonAction::class)->handle(
            $data,
            withPlan: SeasonPlanSteps::canPlan(),
            canIssueUpfront: SeasonPlanSteps::canIssueUpfront(),
        );
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

        $notification = Notification::make()->success()->title(Vocabulary::season($season->name).' creada.');

        if ($players === 0) {
            return $notification;
        }

        return $notification
            ->body($players === 1
                ? '¿Pasamos '.Terms::to('student', 'Jugador')." de {$previous->name} ahora?"
                : '¿Pasamos a '.Terms::gendered('student', 'Jugador', 'los', 'las')." {$players} ".Terms::plural('student', 'Jugador')." de {$previous->name} ahora?")
            ->persistent()
            ->actions([
                Action::make('transfer')
                    ->label('Pasar '.Terms::plural('student', 'Jugador'))
                    ->button()
                    ->url(EnrollmentResource::getUrl('transfer', ['from' => $previous->id, 'to' => $season->id])),
                Action::make('later')->label('Más tarde')->close(),
            ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->fromGuide ? Dashboard::getUrl() : static::getResource()::getUrl('index');
    }
}
