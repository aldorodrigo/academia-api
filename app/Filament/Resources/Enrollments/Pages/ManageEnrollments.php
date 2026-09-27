<?php

namespace App\Filament\Resources\Enrollments\Pages;

use App\Filament\Resources\Enrollments\EnrollmentResource;
use Filament\Resources\Pages\ManageRecords;

/**
 * Lista para ver, filtrar y cambiar estados; se inscribe desde la ficha del jugador.
 */
class ManageEnrollments extends ManageRecords
{
    protected static string $resource = EnrollmentResource::class;
}
