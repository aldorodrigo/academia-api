<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Rol por organización (spatie/permission con teams = organization_id).
 *
 * No usa BelongsToOrganization: spatie filtra por equipo y el panel filtra
 * por el tenant de Filament mediante la relación organization().
 */
class Role extends SpatieRole
{
    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
