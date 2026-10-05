<?php

namespace App\Actions\Roles;

use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Borrar un rol del panel ("Roles y permisos"): solo los creados por la organización y solo si nadie lo tiene
 * (ni asignado, vigente o por empezar, ni en una invitación pendiente). Los roles base (Administrador, Presidente,
 * Tesorero, Técnico, Tutor…) no se borran nunca.
 *
 * Se archiva (soft delete: el historial de quién lo tuvo queda) y el registro de actividad guarda quién lo borró
 * con la foto del rol: nombre, cómo se mostraba y sus permisos.
 */
class DeleteRole
{
    public static function isBase(Role $role): bool
    {
        return OrganizationRole::tryFrom((string) $role->name) !== null;
    }

    /**
     * Alguien lo tiene: asignado (vigente o por empezar), en spatie o en una invitación pendiente.
     */
    public static function inUse(Role $role): bool
    {
        $today = Organization::query()->find($role->organization_id)?->today()->toDateString() ?? today()->toDateString();

        $assigned = RoleAssignment::query()->withoutGlobalScopes()
            ->where('role_id', $role->id)
            ->whereNull('ended_at')
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->exists();

        $synced = DB::table(config('permission.table_names.model_has_roles'))
            ->where(config('permission.column_names.role_pivot_key') ?? 'role_id', $role->id)
            ->exists();

        $invited = Invitation::query()->withoutGlobalScopes()
            ->where('organization_id', $role->organization_id)
            ->whereNull('accepted_at')->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->whereJsonContains('roles', $role->name)
            ->exists();

        return $assigned || $synced || $invited;
    }

    public static function canDelete(Role $role): bool
    {
        return ! self::isBase($role) && ! self::inUse($role);
    }

    /**
     * Frena el borrado de un rol base o en uso (lo llama también el modelo, venga de donde venga el borrado).
     */
    public static function ensureDeletable(Role $role): void
    {
        if (self::isBase($role)) {
            throw ValidationException::withMessages(['role' => 'Los roles base no se pueden borrar.']);
        }

        if (self::inUse($role)) {
            throw ValidationException::withMessages(['role' => 'Este rol lo tiene alguien: sacáselo antes de borrarlo.']);
        }
    }

    public function handle(Role $role, ?User $by): void
    {
        self::ensureDeletable($role);

        DB::transaction(function () use ($role, $by): void {
            $role->loadMissing('permissions');

            activity('roles')->performedOn($role)->causedBy($by)
                ->withProperties([
                    'name' => $role->name,
                    'label' => OrganizationRole::labelFor((string) $role->name),
                    'guard_name' => $role->guard_name,
                    'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
                ])
                ->log('Rol borrado');

            $role->delete();
        });
    }
}
