<?php

namespace App\Filament\Platform\Resources\Organizations\Pages;

use App\Filament\Platform\Resources\Organizations\OrganizationResource;
use Filament\Resources\Pages\EditRecord;

class EditOrganization extends EditRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('index');
    }
}
