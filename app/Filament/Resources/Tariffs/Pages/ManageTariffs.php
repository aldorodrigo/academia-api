<?php

namespace App\Filament\Resources\Tariffs\Pages;

use App\Filament\Resources\Tariffs\TariffResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTariffs extends ManageRecords
{
    protected static string $resource = TariffResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
