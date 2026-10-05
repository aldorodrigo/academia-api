<?php

namespace App\Filament\Resources\EnrollmentRequests\Pages;

use App\Filament\Resources\EnrollmentRequests\EnrollmentRequestResource;
use Filament\Resources\Pages\ManageRecords;

class ManageEnrollmentRequests extends ManageRecords
{
    protected static string $resource = EnrollmentRequestResource::class;
}
