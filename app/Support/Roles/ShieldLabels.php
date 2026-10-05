<?php

namespace App\Support\Roles;

use BezhanSalleh\FilamentShield\FilamentShield;
use Filament\Resources\Resource;
use Illuminate\Support\Str;

/**
 * Etiquetas de "Roles y permisos" como se escriben en español: Shield pasa los nombres por headline()
 * ("Condonar Deudas", "Depósito De Efectivo"); acá van con mayúscula solo al principio ("Condonar deudas").
 */
class ShieldLabels extends FilamentShield
{
    public function getLocalizedResourceLabel(Resource|string $resource): string
    {
        $resource = is_string($resource) ? resolve($resource) : $resource;

        return Str::ucfirst($resource::getModelLabel());
    }

    public function getCustomPermissionLabel(string $key, ?string $configLabel = null): string
    {
        return $configLabel !== null ? Str::ucfirst($configLabel) : Str::ucfirst(Str::lower(Str::headline($key)));
    }
}
