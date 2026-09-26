<?php

namespace App\Support\Roles;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asigna y termina roles manteniendo sincronizado spatie/permission.
 */
class RoleAssigner
{
    public function __construct(private CurrentOrganization $current) {}

    public function assign(
        Organization $organization,
        User $user,
        OrganizationRole|Role|string $role,
        ?CarbonInterface $startsOn = null,
        ?CarbonInterface $endsOn = null,
        ?User $assignedBy = null,
    ): RoleAssignment {
        return $this->current->run($organization, function (Organization $organization) use ($user, $role, $startsOn, $endsOn, $assignedBy) {
            $role = $this->resolveRole($organization, $role);

            if (OrganizationRole::tryFrom($role->name)?->isBoardPosition() && $endsOn === null) {
                throw ValidationException::withMessages([
                    'ends_on' => 'Un cargo de comisión necesita la fecha de fin del mandato.',
                ]);
            }

            if ($startsOn !== null && $endsOn !== null && $endsOn->lt($startsOn)) {
                throw ValidationException::withMessages([
                    'ends_on' => 'El fin del mandato no puede ser anterior al inicio.',
                ]);
            }

            return DB::transaction(function () use ($organization, $user, $role, $startsOn, $endsOn, $assignedBy) {
                $assignment = RoleAssignment::query()->create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'starts_on' => $startsOn?->toDateString(),
                    'ends_on' => $endsOn?->toDateString(),
                    'assigned_by' => $assignedBy?->id,
                ]);

                $this->sync($organization, $user, $role);

                return $assignment;
            });
        });
    }

    public function end(RoleAssignment $assignment): void
    {
        $this->current->run($assignment->organization, function (Organization $organization) use ($assignment) {
            DB::transaction(function () use ($organization, $assignment) {
                $assignment->update(['ended_at' => now()]);

                $this->sync($organization, $assignment->user, $assignment->role);
            });
        });
    }

    /**
     * Deja model_has_roles igual a las asignaciones vigentes de ese rol.
     */
    public function sync(Organization $organization, User $user, Role $role): void
    {
        $this->current->run($organization, function (Organization $organization) use ($user, $role) {
            $active = RoleAssignment::query()
                ->current($organization->today()->toDateString())
                ->where('user_id', $user->id)
                ->where('role_id', $role->id)
                ->exists();

            $user->unsetRelation('roles');

            $active ? $user->assignRole($role) : $user->removeRole($role);

            $user->unsetRelation('roles');
        });
    }

    private function resolveRole(Organization $organization, OrganizationRole|Role|string $role): Role
    {
        if ($role instanceof Role) {
            abort_unless($role->organization_id === $organization->id, 422, 'El rol no pertenece a la organización.');

            return $role;
        }

        $name = $role instanceof OrganizationRole ? $role->value : $role;

        return Role::query()
            ->where('organization_id', $organization->id)
            ->where('name', $name)
            ->where('guard_name', 'web')
            ->firstOrFail();
    }
}
