<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole as ShieldEditRole;

class EditRole extends ShieldEditRole
{
    protected static string $resource = RoleResource::class;

    /**
     * "Borrar" solo en un rol creado por la organización que nadie tiene.
     */
    protected function getActions(): array
    {
        return [RoleResource::deleteAction()];
    }
}
