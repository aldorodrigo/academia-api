<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Filament\Resources\Lessons\BookingResource;
use Filament\Resources\Pages\ListRecords;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;
}
