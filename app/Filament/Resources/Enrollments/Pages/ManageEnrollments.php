<?php

namespace App\Filament\Resources\Enrollments\Pages;

use App\Filament\Resources\Enrollments\EnrollmentResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

/**
 * Lista para ver, filtrar y cambiar estados; se inscribe desde la ficha del jugador
 * y en bloque con el pase de temporada.
 */
class ManageEnrollments extends ManageRecords
{
    protected static string $resource = EnrollmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('transfer')
                ->label('Pase de temporada')
                ->icon(Heroicon::OutlinedArrowRightCircle)
                ->url(fn () => EnrollmentResource::getUrl('transfer'))
                ->visible(fn () => SeasonTransfer::canAccess()),
        ];
    }
}
