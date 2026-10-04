<?php

namespace App\Filament\Resources\Students\Pages;

use App\Actions\Students\RegisterStudent;
use App\Enums\EnrollmentStatus;
use App\Enums\MidPeriod;
use App\Exceptions\ImportRowException;
use App\Filament\Resources\Students\StudentResource;
use App\Models\Group;
use App\Models\Season;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Alta en un paso: datos + inscripción + tutores (RegisterStudent). A los tutores se los
 * invita a la app después, desde la ficha (por correo o WhatsApp, como al técnico).
 * La ficha médica la guarda Filament después, como relación de la sección.
 */
class CreateStudent extends CreateRecord
{
    protected static string $resource = StudentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(RegisterStudent::class)->handle(
                Filament::getTenant(),
                collect($data)->only(['first_name', 'last_name', 'document', 'birth_date', 'shirt_size', 'position', 'notes', 'user_id'])->all(),
                Group::query()->findOrFail($data['group_id']),
                Season::query()->findOrFail($data['season_id']),
                $data['status'] instanceof EnrollmentStatus ? $data['status'] : EnrollmentStatus::from($data['status']),
                $data['guardians'] ?? [],
                auth()->user(),
                mustBeNew: true,
                midPeriod: filled($data['mid_period'] ?? null) ? MidPeriod::from($data['mid_period'] instanceof MidPeriod ? $data['mid_period']->value : $data['mid_period']) : null,
            );
        } catch (ImportRowException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            $this->halt();
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        $toInvite = $this->getRecord()->guardians()
            ->whereNull('user_id')
            ->where(fn ($query) => $query->whereNotNull('email')->orWhereNotNull('phone'))
            ->exists();

        return parent::getCreatedNotification()
            ?->body($toInvite ? 'Invitá a sus tutores a la app desde la sección Tutores.' : null);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Jugador inscripto.';
    }
}
