<?php

namespace App\Models;

use App\Actions\Roles\DeleteRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Rol por organización (spatie/permission con teams = organization_id).
 *
 * No usa BelongsToOrganization: spatie filtra por equipo y el panel filtra
 * por el tenant de Filament mediante la relación organization().
 *
 * No se borra físicamente: se archiva (soft delete) y solo si es un rol creado por la organización que nadie
 * tiene (`DeleteRole`). Los roles base no se borran nunca; el guardia de `deleting` lo frena venga de donde venga.
 */
class Role extends SpatieRole
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::deleting(function (Role $role): void {
            DeleteRole::ensureDeletable($role);
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
