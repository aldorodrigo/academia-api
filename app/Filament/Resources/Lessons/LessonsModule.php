<?php

namespace App\Filament\Resources\Lessons;

use App\Enums\Feature;
use App\Models\Organization;
use Filament\Facades\Filament;

/**
 * Los recursos de clases particulares solo aparecen con el módulo activo.
 */
trait LessonsModule
{
    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $tenant->hasFeature(Feature::PrivateLessons) && parent::canAccess();
    }
}
