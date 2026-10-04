<?php

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Role;
use App\Support\Roles\DefaultPermissions;

/**
 * Crea los roles base de la organización que todavía no existan, con sus permisos por defecto.
 */
class EnsureOrganizationRoles
{
    /**
     * @param  bool  $fillEmpty  también a los roles que ya existían sin ningún permiso (clubes anteriores)
     */
    public function handle(Organization $organization, bool $fillEmpty = false): void
    {
        foreach (OrganizationRole::cases() as $base) {
            $role = Role::query()->firstOrCreate([
                'organization_id' => $organization->id,
                'name' => $base->value,
                'guard_name' => 'web',
            ]);

            if ($role->wasRecentlyCreated || $fillEmpty) {
                DefaultPermissions::apply($role);
            }
        }
    }
}
