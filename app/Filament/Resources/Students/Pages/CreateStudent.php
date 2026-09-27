<?php

namespace App\Filament\Resources\Students\Pages;

use App\Actions\Students\RegisterStudent;
use App\Enums\EnrollmentStatus;
use App\Exceptions\ImportRowException;
use App\Filament\Resources\Students\StudentResource;
use App\Models\Group;
use App\Models\Season;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Alta en un paso: datos + inscripción + tutores + invitación (RegisterStudent).
 * La ficha médica la guarda Filament después, como relación de la sección.
 */
class CreateStudent extends CreateRecord
{
    protected static string $resource = StudentResource::class;

    private int $invited = 0;

    protected function handleRecordCreation(array $data): Model
    {
        $action = app(RegisterStudent::class);

        try {
            $student = $action->handle(
                Filament::getTenant(),
                collect($data)->only(['first_name', 'last_name', 'document', 'birth_date', 'shirt_size', 'position', 'notes', 'user_id'])->all(),
                Group::query()->findOrFail($data['group_id']),
                Season::query()->findOrFail($data['season_id']),
                $data['status'] instanceof EnrollmentStatus ? $data['status'] : EnrollmentStatus::from($data['status']),
                $data['guardians'] ?? [],
                auth()->user(),
            );
        } catch (ImportRowException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            $this->halt();
        }

        $this->invited = $action->invited;

        return $student;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->invited > 0
            ? "Jugador inscripto. Invitaciones enviadas: {$this->invited}."
            : 'Jugador inscripto.';
    }
}
