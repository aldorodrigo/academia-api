<?php

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Role;

/**
 * Crea los roles base de la organización que todavía no existan.
 */
class EnsureOrganizationRoles
{
    public function handle(Organization $organization): void
    {
        foreach (OrganizationRole::cases() as $role) {
            Role::query()->firstOrCreate([
                'organization_id' => $organization->id,
                'name' => $role->value,
                'guard_name' => 'web',
            ]);
        }
    }
}
