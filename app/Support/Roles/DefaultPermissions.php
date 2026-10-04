<?php

namespace App\Support\Roles;

use App\Enums\OrganizationRole;
use App\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos con los que nace cada rol base (business-logic.md §2), para que la comisión vea lo
 * suyo sin que el administrador tenga que armarlo en Roles. El administrador los puede cambiar.
 * El admin no los necesita (pasa por Gate::before); técnico y tutor usan sus grupos e hijos.
 */
class DefaultPermissions
{
    private const FINANCE = ['Payment', 'Charge', 'Expense', 'RecurringExpense', 'MoneyAccount', 'Transfer', 'Tariff', 'Scholarship', 'DiscountRule', 'Supplier'];

    private const PEOPLE = ['Student', 'Guardian', 'Enrollment'];

    private const ACADEMIC = ['Group', 'Season'];

    private const VIEW = ['ViewAny', 'View'];

    private const MANAGE = ['ViewAny', 'View', 'Create', 'Update', 'Delete'];

    /**
     * @return list<string>
     */
    public static function for(OrganizationRole $role): array
    {
        $everything = [...self::FINANCE, ...self::PEOPLE, ...self::ACADEMIC, 'Invitation', 'Membership'];

        return match ($role) {
            OrganizationRole::President => [...self::grant($everything, self::VIEW), 'View:Reports', 'Approve:Scholarship'],
            OrganizationRole::VicePresident => [...self::grant($everything, self::VIEW), 'View:Reports'],
            OrganizationRole::Treasurer => [
                ...self::grant(self::FINANCE, self::MANAGE),
                ...self::grant([...self::PEOPLE, ...self::ACADEMIC], self::VIEW),
                'View:Reports', 'Approve:Scholarship',
            ],
            OrganizationRole::DeputyTreasurer => [
                ...self::grant(self::FINANCE, self::MANAGE),
                ...self::grant([...self::PEOPLE, ...self::ACADEMIC], self::VIEW),
                'View:Reports',
            ],
            // Invitan a tutores y técnicos (no a la comisión: ver RoleFields::options).
            OrganizationRole::Secretary, OrganizationRole::DeputySecretary => [
                ...self::grant(self::PEOPLE, self::MANAGE),
                ...self::grant(['Invitation'], ['ViewAny', 'View', 'Create', 'Update', 'Delete']),
                ...self::grant([...self::ACADEMIC, 'Membership'], self::VIEW),
            ],
            OrganizationRole::Member => ['View:Reports'],
            OrganizationRole::Auditor => [...self::grant(self::FINANCE, self::VIEW), 'View:Reports'],
            OrganizationRole::Admin, OrganizationRole::Instructor, OrganizationRole::Guardian => [],
        };
    }

    /**
     * Le da al rol sus permisos por defecto. Con $onlyIfEmpty no toca un rol que ya tiene permisos
     * (los eligió el administrador).
     */
    public static function apply(Role $role, bool $onlyIfEmpty = true): void
    {
        $base = OrganizationRole::tryFrom($role->name);

        if ($base === null || ($onlyIfEmpty && $role->permissions()->exists())) {
            return;
        }

        $names = self::for($base);

        if ($names === []) {
            return;
        }

        foreach ($names as $name) {
            Permission::findOrCreate($name, $role->guard_name);
        }

        $role->givePermissionTo($names);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  list<string>  $models
     * @param  list<string>  $abilities
     * @return list<string>
     */
    private static function grant(array $models, array $abilities): array
    {
        return collect($models)->crossJoin($abilities)->map(fn (array $pair) => "{$pair[1]}:{$pair[0]}")->all();
    }
}
