<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Filament\Resources\Lessons\ClassPackResource;
use Filament\Resources\Pages\ListRecords;

class ListClassPacks extends ListRecords
{
    protected static string $resource = ClassPackResource::class;
}
